(() => {
  const page = document.querySelector('[data-video-call-page]');
  const dataNode = document.getElementById('video-call-data');
  if (!page || !dataNode) {
    return;
  }

  const notify = (type, message) => {
    if (typeof window.showNotification === 'function') {
      window.showNotification(type, message);
    }
  };

  let pageData;
  try {
    pageData = JSON.parse(dataNode.textContent || '{}');
  } catch (error) {
    return;
  }

  const statusPill = page.querySelector('[data-video-call-status-pill]');
  const errorBox = page.querySelector('[data-video-call-error]');
  const localVideo = page.querySelector('[data-video-call-local-video]');
  const localEmpty = page.querySelector('[data-video-call-local-empty]');
  const remoteVideo = page.querySelector('[data-video-call-remote-video]');
  const remoteEmpty = page.querySelector('[data-video-call-remote-empty]');
  const remoteEmptyText = page.querySelector('[data-video-call-remote-empty-text]');
  const micButton = page.querySelector('[data-video-call-toggle-mic]');
  const cameraButton = page.querySelector('[data-video-call-toggle-camera]');
  const leaveButton = page.querySelector('[data-video-call-leave]');

  const setStatusPill = (text, tone) => {
    if (!statusPill) return;
    statusPill.textContent = text;
    statusPill.className = 'pill' + (tone ? ` is-${tone}` : '');
  };

  const showError = (message) => {
    if (!errorBox) return;
    errorBox.textContent = message;
    errorBox.hidden = !message;
  };

  const setRemoteEmptyText = (text) => {
    if (remoteEmptyText) remoteEmptyText.textContent = text;
  };

  const enableCameraButton = page.querySelector('[data-video-call-enable-camera]');
  const hearAudioButton = page.querySelector('[data-video-call-hear-audio]');
  const micLiveBadge = page.querySelector('[data-video-call-mic-live]');
  const isLocalHost = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);
  const localhostUrl = `http://localhost${window.location.pathname}${window.location.search}`;
  if (!window.isSecureContext && !isLocalHost) {
    showError(`Camera access is blocked on ${window.location.hostname}. Open ${localhostUrl} (or use https://), allow the camera, then reload.`);
    setStatusPill('Needs HTTPS', 'danger');
  }

  if (!window.RTCPeerConnection) {
    showError('This browser cannot start a video call. Use Chrome, Edge, or Safari, and open the site on localhost or https.');
    setStatusPill('Unavailable', 'danger');
    notify('error', 'Video call is not available in this browser.');
    return;
  }
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    showError(`This browser cannot start a video call. Use Chrome, Edge, or Safari, and open LexShield at ${isLocalHost ? 'this localhost address' : localhostUrl} or https://.`);
    setStatusPill('Unavailable', 'danger');
    notify('error', 'Video call is not available in this browser.');
    return;
  }

  const defaultIce = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
  ];
  const ICE_SERVERS = (pageData.iceServers && pageData.iceServers.length) ? pageData.iceServers : defaultIce;
  const POLL_INTERVAL_MS = 1000;
  const peerUserId = Number(pageData.peerUserId || 0);
  const isInboxCall = peerUserId > 0;
  const isInitiator = isInboxCall
    ? pageData.isInitiator === true
    : pageData.role === 'lawyer';

  let localStream = null;
  let pc = null;
  let sinceId = Number(pageData.sinceId || 0);
  let pollTimer = null;
  let ended = false;
  let offerInFlight = false;
  let remoteDescriptionSet = false;
  let pendingCandidates = [];
  let micOn = true;
  let cameraOn = true;
  let makingOffer = false;
  let ringTimeout = null;
  const RING_TIMEOUT_MS = 45000;

  async function playLocal(video) {
    if (!(video instanceof HTMLVideoElement) || !video.srcObject) return;
    video.muted = true;
    video.volume = 0;
    video.playsInline = true;
    try {
      await video.play();
    } catch (error) {
      try {
        await video.play();
      } catch (mutedError) {
        // Local preview can wait until the user taps a control.
      }
    }
  }

  async function playRemote(video) {
    if (!(video instanceof HTMLVideoElement) || !video.srcObject) return;
    video.muted = false;
    video.defaultMuted = false;
    video.volume = 1;
    video.playsInline = true;
    video.setAttribute('playsinline', '');
    try {
      await video.play();
      showHearAudio(false);
    } catch (error) {
      showHearAudio(true);
      showError('Tap Hear audio. Browsers block call sound until you tap.');
    }
  }

  async function postSignal(type, payload) {
    try {
      const response = await fetch(pageData.signalEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          appointment_id: pageData.appointmentId,
          peer_user_id: peerUserId || undefined,
          csrf_token: pageData.csrfToken,
          type,
          payload: payload || {},
        }),
      });
      const data = await response.json().catch(() => ({ ok: false }));
      if (!data || data.ok !== true) {
        const message = (data && data.message) ? String(data.message) : 'The call server did not accept that update.';
        showError(message);
      }
      return data;
    } catch (error) {
      return { ok: false };
    }
  }

  function waitForIceGathering(connection, timeoutMs) {
    if (!connection || connection.iceGatheringState === 'complete') {
      return Promise.resolve();
    }
    return new Promise((resolve) => {
      let settled = false;
      const finish = () => {
        if (settled) return;
        settled = true;
        connection.removeEventListener('icegatheringstatechange', onChange);
        window.clearTimeout(timer);
        resolve();
      };
      const onChange = () => {
        if (connection.iceGatheringState === 'complete') finish();
      };
      const timer = window.setTimeout(finish, timeoutMs || 2500);
      connection.addEventListener('icegatheringstatechange', onChange);
    });
  }

  function closePeerConnection() {
    if (pc) {
      try {
        pc.close();
      } catch (error) {
        // Already closed.
      }
    }
    pc = null;
    remoteDescriptionSet = false;
    pendingCandidates = [];
  }

  async function flushQueuedCandidates(connection) {
    const queued = pendingCandidates;
    pendingCandidates = [];
    for (const candidate of queued) {
      try {
        await connection.addIceCandidate(new RTCIceCandidate(candidate));
      } catch (error) {
        // Ignore stale/duplicate candidates.
      }
    }
  }

  function ensurePeerConnection() {
    if (pc) return pc;

    pc = new RTCPeerConnection({ iceServers: ICE_SERVERS, iceCandidatePoolSize: 2 });
    remoteDescriptionSet = false;

    if (localStream) {
      attachLocalTracksToPeer(pc);
    }

    pc.onicecandidate = (event) => {
      if (event.candidate) {
        postSignal('candidate', event.candidate.toJSON());
      }
    };

    pc.ontrack = (event) => {
      if (remoteVideo) {
        const incoming = event.streams && event.streams[0] ? event.streams[0] : null;
        let stream = remoteVideo.srcObject instanceof MediaStream ? remoteVideo.srcObject : null;
        if (incoming && (!stream || stream.id !== incoming.id)) {
          stream = incoming;
          remoteVideo.srcObject = stream;
        } else {
          if (!stream) {
            stream = new MediaStream();
            remoteVideo.srcObject = stream;
          }
          if (event.track && !stream.getTracks().includes(event.track)) {
            stream.addTrack(event.track);
          }
        }
        playRemote(remoteVideo);
      }
      if (remoteEmpty) remoteEmpty.hidden = true;
      setStatusPill('Connected', 'success');
      showError('');
    };

    pc.onconnectionstatechange = () => {
      if (!pc) return;
      if (pc.connectionState === 'connected') {
        setStatusPill('Connected', 'success');
      }
      if (pc.connectionState === 'failed' || pc.connectionState === 'disconnected') {
        setStatusPill('Reconnecting…', 'warning');
      }
      if (pc.connectionState === 'closed') {
        if (remoteEmpty) remoteEmpty.hidden = false;
      }
    };

    return pc;
  }

  async function createAndSendOffer() {
    if (offerInFlight || ended || !localStream) return;
    offerInFlight = true;
    makingOffer = true;
    try {
      closePeerConnection();
      const connection = ensurePeerConnection();
      const offer = await connection.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: true });
      await connection.setLocalDescription(offer);
      await waitForIceGathering(connection, 2500);
      const description = connection.localDescription || offer;
      await postSignal('offer', { type: description.type, sdp: description.sdp });
      setStatusPill('Calling…', 'info');
    } catch (error) {
      showError('Unable to start the call. Please reload the page.');
    } finally {
      makingOffer = false;
      offerInFlight = false;
    }
  }

  async function handleSignal(signal) {
    if (!signal || !signal.type) return;

    if (signal.type === 'join') {
      if (isInitiator && localStream && !ended) {
        await createAndSendOffer();
      }
      return;
    }

    if (signal.type === 'offer') {
      if (isInitiator && (makingOffer || (pc && pc.signalingState !== 'stable'))) {
        return;
      }
      try {
        if (pc && pc.signalingState !== 'stable') {
          closePeerConnection();
        }
        const connection = ensurePeerConnection();
        await connection.setRemoteDescription(new RTCSessionDescription(signal.payload));
        remoteDescriptionSet = true;
        await flushQueuedCandidates(connection);
        const answer = await connection.createAnswer();
        await connection.setLocalDescription(answer);
        await waitForIceGathering(connection, 2500);
        const description = connection.localDescription || answer;
        await postSignal('answer', { type: description.type, sdp: description.sdp });
        setStatusPill('Connecting…', 'info');
      } catch (error) {
        showError('Could not answer the call. Reload the page and try again.');
      }
      return;
    }

    if (signal.type === 'answer') {
      if (!pc) return;
      try {
        if (pc.signalingState === 'have-local-offer') {
          await pc.setRemoteDescription(new RTCSessionDescription(signal.payload));
          remoteDescriptionSet = true;
          await flushQueuedCandidates(pc);
        }
      } catch (error) {
        showError('Could not finish connecting. Reload the page and try again.');
      }
      return;
    }

    if (signal.type === 'candidate') {
      if (!signal.payload) return;
      if (!pc || !remoteDescriptionSet) {
        pendingCandidates.push(signal.payload);
        return;
      }
      try {
        await pc.addIceCandidate(new RTCIceCandidate(signal.payload));
      } catch (error) {
        // Ignore stale/duplicate candidates.
      }
      return;
    }

    if (signal.type === 'media-state') {
      const parts = [];
      if (signal.payload && signal.payload.micOn === false) parts.push('mic muted');
      if (signal.payload && signal.payload.cameraOn === false) parts.push('camera off');
      setRemoteEmptyText(
        parts.length
          ? `${pageData.counterpartName} has their ${parts.join(' and ')}.`
          : `Waiting for ${pageData.counterpartName} to join…`
      );
      return;
    }

    if (signal.type === 'leave' || signal.type === 'decline') {
      finishCall(
        signal.type === 'decline'
          ? `${pageData.counterpartName} declined the call.`
          : `${pageData.counterpartName} ended the call.`
      );
    }
  }

  function finishCall(reason) {
    if (ended) return;
    ended = true;
    if (ringTimeout) {
      window.clearTimeout(ringTimeout);
      ringTimeout = null;
    }
    stopPolling();
    if (typeof micMeterStop === 'function') {
      micMeterStop();
      micMeterStop = null;
    }
    closePeerConnection();
    localStream?.getTracks().forEach((track) => track.stop());
    if (reason) {
      notify('info', reason);
    }
    window.location.href = pageData.backUrl || '/';
  }

  async function poll() {
    if (ended) return;
    try {
      const url = new URL(pageData.signalEndpoint, window.location.href);
      if (peerUserId > 0) {
        url.searchParams.set('peer_user_id', String(peerUserId));
      } else {
        url.searchParams.set('appointment_id', String(pageData.appointmentId));
      }
      url.searchParams.set('since_id', String(sinceId));
      const response = await fetch(url.toString(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!data || data.ok !== true) {
        if (data && data.message) showError(String(data.message));
        return;
      }

      if (data.sessionStatus === 'ended') {
        finishCall(`${pageData.counterpartName} ended the call.`);
        return;
      }

      sinceId = data.lastId || sinceId;

      for (const signal of data.signals || []) {
        await handleSignal(signal);
      }
      if (ended) return;

      if (data.otherPresent && ringTimeout) {
        window.clearTimeout(ringTimeout);
        ringTimeout = null;
      }

      if (isInitiator && data.otherPresent && !pc && !offerInFlight && localStream) {
        setStatusPill('Calling…', 'info');
        await createAndSendOffer();
      } else if (data.otherPresent && !pc) {
        setStatusPill('Connecting…', 'info');
      } else if (!data.otherPresent && !pc) {
        const ringing = isInboxCall && (data.sessionStatus === 'ringing' || pageData.isCaller === true);
        setStatusPill(ringing ? 'Ringing…' : 'Waiting', ringing ? 'info' : 'neutral');
        setRemoteEmptyText(
          ringing
            ? `Calling ${pageData.counterpartName}…`
            : `Waiting for ${pageData.counterpartName} to join…`
        );
      }
    } catch (error) {
      // Network hiccups are expected during polling; keep retrying silently.
    }
  }

  function startPolling() {
    if (pollTimer) return;
    poll();
    pollTimer = window.setInterval(poll, POLL_INTERVAL_MS);
  }

  function stopPolling() {
    if (pollTimer) {
      window.clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  const mediaErrorMessage = (error) => {
    const name = error && error.name ? String(error.name) : String(error || '');
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
      return 'Camera and microphone access is required. Click the lock icon in the address bar, allow Camera and Microphone, then tap Turn on camera.';
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
      return 'No camera was found. Plug in a webcam or turn the camera on in Windows Settings → Privacy & security → Camera.';
    }
    if (name === 'NotReadableError' || name === 'TrackStartError') {
      return 'The camera is already in use. Close Zoom, Teams, Skype, or the Camera app, then tap Turn on camera.';
    }
    if (name === 'OverconstrainedError') {
      return 'This camera rejected the video settings. Tap Turn on camera to try again.';
    }
    if (name === 'SecurityError' || (!window.isSecureContext && !isLocalHost)) {
      return `Camera access is blocked on this address. Open ${localhostUrl} or https://, then tap Turn on camera.`;
    }
    return 'Camera and microphone access is required. Click Allow if the browser asks, then tap Turn on camera.';
  };

  const showEnableCamera = (visible) => {
    if (enableCameraButton) {
      enableCameraButton.hidden = !visible;
    }
  };

  const showHearAudio = (visible) => {
    if (hearAudioButton) {
      hearAudioButton.hidden = !visible;
    }
  };

  const attachLocalPreview = () => {
    if (localStream && localVideo) {
      localVideo.srcObject = localStream;
      localVideo.muted = true;
      localVideo.volume = 0;
      localVideo.playsInline = true;
      localVideo.setAttribute('playsinline', '');
      playLocal(localVideo);
    }
    const hasVideo = Boolean(localStream && cameraOn && localStream.getVideoTracks().some((track) => track.readyState === 'live'));
    const hasAudio = Boolean(localStream && localStream.getAudioTracks().some((track) => track.readyState === 'live' && track.enabled));
    if (localEmpty) localEmpty.hidden = hasVideo;
    showEnableCamera(!hasVideo);
    if (cameraButton) {
      cameraButton.classList.toggle('is-off', !hasVideo);
      cameraButton.setAttribute('aria-pressed', String(hasVideo));
      cameraButton.title = hasVideo ? 'Turn off camera' : 'Turn on camera';
    }
    if (micButton) {
      micButton.classList.toggle('is-off', !hasAudio || !micOn);
      micButton.setAttribute('aria-pressed', String(hasAudio && micOn));
    }
  };

  const attachLocalTracksToPeer = (connection) => {
    if (!connection || !localStream) return;
    localStream.getTracks().forEach((track) => {
      const sender = connection.getSenders().find((item) => item.track && item.track.kind === track.kind);
      if (sender) {
        sender.replaceTrack(track).catch(() => {});
        return;
      }
      connection.addTrack(track, localStream);
    });
  };

  async function acquireLocalStream(preferVideo) {
    const audioConstraints = {
      echoCancellation: true,
      noiseSuppression: true,
      autoGainControl: true,
    };
    const combos = preferVideo
      ? [
        { audio: audioConstraints, video: { facingMode: 'user' } },
        { audio: true, video: true },
        { audio: audioConstraints, video: false },
        { audio: true, video: false },
      ]
      : [
        { audio: audioConstraints, video: false },
        { audio: true, video: false },
      ];

    let lastError = null;
    for (const constraints of combos) {
      try {
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        if (preferVideo && stream.getAudioTracks().length > 0 && stream.getVideoTracks().length === 0) {
          try {
            const videoOnly = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            videoOnly.getVideoTracks().forEach((track) => stream.addTrack(track));
          } catch (videoError) {
            // Keep the microphone even if the camera is missing.
          }
        }
        return stream;
      } catch (error) {
        lastError = error;
        if (error && error.name === 'SecurityError') {
          throw error;
        }
      }
    }
    if (preferVideo) {
      try {
        return await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      } catch (error) {
        lastError = error;
      }
    }
    throw lastError || new Error('NotFoundError');
  }

  let micMeterStop = null;
  function startMicMeter() {
    if (typeof micMeterStop === 'function') {
      micMeterStop();
      micMeterStop = null;
    }
    if (!micLiveBadge || !localStream) return;
    const audioTrack = localStream.getAudioTracks().find((track) => track.readyState === 'live');
    if (!audioTrack) {
      micLiveBadge.hidden = true;
      return;
    }
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      const ctx = new AudioCtx();
      const source = ctx.createMediaStreamSource(new MediaStream([audioTrack]));
      const analyser = ctx.createAnalyser();
      analyser.fftSize = 256;
      source.connect(analyser);
      const data = new Uint8Array(analyser.fftSize);
      let frame = 0;
      const tick = () => {
        analyser.getByteTimeDomainData(data);
        let sum = 0;
        for (let i = 0; i < data.length; i++) {
          const v = (data[i] - 128) / 128;
          sum += v * v;
        }
        const talking = micOn && Math.sqrt(sum / data.length) > 0.03;
        micLiveBadge.hidden = !talking;
        frame = window.requestAnimationFrame(tick);
      };
      tick();
      micMeterStop = () => {
        window.cancelAnimationFrame(frame);
        micLiveBadge.hidden = true;
        ctx.close().catch(() => {});
      };
    } catch (error) {
      micLiveBadge.hidden = true;
    }
  }

  async function startOrUpgradeCamera() {
    setStatusPill('Setting up your camera…', 'neutral');
    try {
      if (localStream && localStream.getVideoTracks().some((track) => track.readyState === 'live')) {
        cameraOn = true;
        localStream.getVideoTracks().forEach((track) => {
          track.enabled = true;
        });
        attachLocalPreview();
        attachLocalTracksToPeer(pc);
        startMicMeter();
        showError('');
        return true;
      }

      const nextStream = await acquireLocalStream(true);
      if (localStream && localStream !== nextStream) {
        nextStream.getTracks().forEach((track) => {
          const same = localStream.getTracks().find((existing) => existing.kind === track.kind && existing.readyState === 'live');
          if (same && track.kind === 'audio') {
            track.stop();
            return;
          }
          localStream.addTrack(track);
        });
      } else {
        localStream = nextStream;
      }
      cameraOn = localStream.getVideoTracks().length > 0;
      micOn = localStream.getAudioTracks().some((track) => track.readyState === 'live');
      attachLocalPreview();
      startMicMeter();
      if (pc) {
        attachLocalTracksToPeer(pc);
        if (isInitiator && (localStream.getVideoTracks().length > 0 || localStream.getAudioTracks().length > 0)) {
          await createAndSendOffer();
        }
      }
      if (!micOn) {
        showError('Microphone is off. Click the lock icon, allow Microphone, then tap the mic button.');
      } else if (cameraOn) {
        showError('');
      } else {
        showError('Camera is unavailable, so this call is audio-only. Check the camera permission and tap Turn on camera.');
      }
      return cameraOn;
    } catch (error) {
      showError(mediaErrorMessage(error));
      setStatusPill('Camera blocked', 'danger');
      showEnableCamera(true);
      notify('error', 'Camera/microphone access was denied.');
      return false;
    }
  }

  async function init() {
    startPolling();
    await startOrUpgradeCamera();
    if (!localStream) {
      showEnableCamera(true);
    }

    await postSignal('join', {});
    if (isInboxCall && pageData.isCaller === true) {
      setStatusPill('Ringing…', 'info');
      setRemoteEmptyText(`Calling ${pageData.counterpartName}…`);
      ringTimeout = window.setTimeout(async () => {
        await postSignal('leave', {});
        finishCall('No answer.');
      }, RING_TIMEOUT_MS);
    } else {
      setStatusPill('Waiting', 'neutral');
      setRemoteEmptyText(`Waiting for ${pageData.counterpartName} to join…`);
    }
  }

  micButton?.addEventListener('click', async () => {
    const liveAudio = localStream && localStream.getAudioTracks().some((track) => track.readyState === 'live');
    if (!liveAudio) {
      await startOrUpgradeCamera();
      postSignal('media-state', { micOn, cameraOn });
      return;
    }
    micOn = !micOn;
    localStream.getAudioTracks().forEach((track) => {
      track.enabled = micOn;
    });
    micButton.classList.toggle('is-off', !micOn);
    micButton.setAttribute('aria-pressed', String(micOn));
    micButton.title = micOn ? 'Mute microphone' : 'Unmute microphone';
    postSignal('media-state', { micOn, cameraOn });
  });

  cameraButton?.addEventListener('click', async () => {
    const liveVideo = localStream && localStream.getVideoTracks().some((track) => track.readyState === 'live');
    if (!liveVideo) {
      await startOrUpgradeCamera();
      postSignal('media-state', { micOn, cameraOn });
      return;
    }
    cameraOn = !cameraOn;
    localStream.getVideoTracks().forEach((track) => {
      track.enabled = cameraOn;
    });
    attachLocalPreview();
    postSignal('media-state', { micOn, cameraOn });
  });

  enableCameraButton?.addEventListener('click', async () => {
    await startOrUpgradeCamera();
    postSignal('media-state', { micOn, cameraOn });
  });

  const unmuteRemote = async () => {
    if (remoteVideo) {
      await playRemote(remoteVideo);
    }
  };
  hearAudioButton?.addEventListener('click', unmuteRemote);
  remoteVideo?.addEventListener('click', unmuteRemote);

  async function leaveCall() {
    if (ended) return;
    await postSignal('leave', {});
    finishCall('');
  }

  leaveButton?.addEventListener('click', () => {
    leaveCall();
  });

  window.addEventListener('beforeunload', () => {
    if (!ended) {
      navigator.sendBeacon?.(
        pageData.signalEndpoint,
        new Blob(
          [JSON.stringify({
            appointment_id: pageData.appointmentId,
            peer_user_id: peerUserId || undefined,
            csrf_token: pageData.csrfToken,
            type: 'leave',
            payload: {},
          })],
          { type: 'application/json' }
        )
      );
    }
  });

  init();
})();
