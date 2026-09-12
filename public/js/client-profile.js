(() => {
  const { useEffect, useRef, useState } = React;

  const dataNode = document.getElementById('client-profile-app-data');
  const mountNode = document.getElementById('client-profile-app');
  if (!dataNode || !mountNode || !window.ReactDOM) {
    return;
  }

  const pageData = JSON.parse(dataNode.textContent || '{}');
  const profile = pageData.profile || {};
  const ui = pageData.ui || {};

  const riskToneMap = {
    low: 'calm',
    medium: 'watch',
    high: 'alert',
  };

  const riskLabel = (value) => {
    const normalized = String(value || 'low').trim().toLowerCase();
    return normalized.charAt(0).toUpperCase() + normalized.slice(1);
  };

  function Icon({ name }) {
    const icons = {
      mail: (
        <path d="M4 6h16v12H4z M4 7l8 6 8-6" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      ),
      phone: (
        <path d="M5 4h4l2 5-3 2c1.5 3 3.5 5 6 6l2-3 5 2v4c0 1-1 2-2 2C9 22 2 15 2 6c0-1 1-2 2-2h1z" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      ),
      location: (
        <path d="M12 21s6-5.4 6-11a6 6 0 1 0-12 0c0 5.6 6 11 6 11Z M12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      ),
      shield: (
        <path d="M12 3 5 6v5c0 4.4 2.9 8.5 7 10 4.1-1.5 7-5.6 7-10V6l-7-3Z" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      ),
      spark: (
        <path d="m12 3 1.8 4.9L19 10l-5.2 2.1L12 17l-1.8-4.9L5 10l5.2-2.1L12 3Z" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      ),
      edit: (
        <path d="M4 20h4l10-10-4-4L4 16v4Z M13 7l4 4" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      ),
      user: (
        <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z M4 20a8 8 0 0 1 16 0" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      ),
      calendar: (
        <path d="M7 3v3 M17 3v3 M4 9h16 M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      ),
    };

    return (
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        {icons[name] || null}
      </svg>
    );
  }

  function Avatar({ src, initials, alt, large = false }) {
    return src ? (
      <img className={`client-profile-avatar ${large ? 'is-large' : ''}`} src={src} alt={alt} />
    ) : (
      <div className={`client-profile-avatar client-profile-avatar--fallback ${large ? 'is-large' : ''}`} aria-hidden="true">
        {initials}
      </div>
    );
  }

  function DetailCard({ icon, label, value, hint }) {
    return (
      <article className="client-profile-detail-card">
        <div className="client-profile-detail-icon">
          <Icon name={icon} />
        </div>
        <div className="client-profile-detail-copy">
          <span>{label}</span>
          <strong>{value || 'Not provided'}</strong>
          {hint ? <small>{hint}</small> : null}
        </div>
      </article>
    );
  }

  function ProfileEditor({ isOpen, onClose }) {
    const fileInputRef = useRef(null);
    const [previewUrl, setPreviewUrl] = useState(profile.avatarUrl || '');
    const [previewName, setPreviewName] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
      if (!isOpen) {
        return undefined;
      }

      const onKeyDown = (event) => {
        if (event.key === 'Escape') {
          onClose();
        }
      };

      document.addEventListener('keydown', onKeyDown);
      return () => document.removeEventListener('keydown', onKeyDown);
    }, [isOpen, onClose]);

    useEffect(() => {
      if (ui.openEditor && isOpen && fileInputRef.current) {
        const input = fileInputRef.current.form?.querySelector('input[name="full_name"]');
        if (input) {
          window.setTimeout(() => input.focus(), 30);
        }
      }
    }, [isOpen]);

    useEffect(() => () => {
      if (previewUrl && previewUrl.startsWith('blob:')) {
        URL.revokeObjectURL(previewUrl);
      }
    }, [previewUrl]);

    if (!isOpen) {
      return null;
    }

    const handleFileChange = (event) => {
      const file = event.target.files?.[0];
      if (previewUrl && previewUrl.startsWith('blob:')) {
        URL.revokeObjectURL(previewUrl);
      }
      if (!file) {
        setPreviewUrl(profile.avatarUrl || '');
        setPreviewName('');
        return;
      }
      setPreviewUrl(URL.createObjectURL(file));
      setPreviewName(file.name);
    };

    return (
      <div className="modal-overlay is-open client-profile-modal-overlay" aria-hidden="false" onClick={(event) => {
        if (event.target === event.currentTarget) {
          onClose();
        }
      }}>
        <div className="modal-card wide client-profile-modal" role="dialog" aria-modal="true" aria-labelledby="clientProfileEditorTitle">
          <div className="modal-header client-profile-modal-header">
            <div>
              <h2 id="clientProfileEditorTitle">Edit your profile</h2>
              <p className="modal-note">Update your contact details, profile photo, email, or password in one place.</p>
            </div>
            <button className="close-button" type="button" onClick={onClose} aria-label="Close">&times;</button>
          </div>
          <div className="modal-body">
            {ui.error ? <div className="alert alert-error">{ui.error}</div> : null}
            <form
              method="post"
              action={pageData.submitUrl}
              className="client-profile-form"
              encType="multipart/form-data"
              onSubmit={() => setIsSubmitting(true)}
            >
              <input type="hidden" name="csrf_token" value={pageData.csrfToken || ''} />

              <section className="client-profile-form-section is-hero">
                <div className="client-profile-form-preview">
                  <Avatar
                    src={previewUrl}
                    initials={profile.initials || 'CL'}
                    alt={`Avatar for ${profile.fullName || 'Client'}`}
                    large
                  />
                  <div>
                    <strong>{profile.fullName || 'Client'}</strong>
                    <span>{previewName || 'Accepted formats: JPG, PNG, GIF, WEBP. Maximum file size: 5 MB.'}</span>
                  </div>
                </div>
                <label className="client-profile-file-picker">
                  <span>Replace photo</span>
                  <input
                    ref={fileInputRef}
                    type="file"
                    name="avatar_image"
                    accept="image/png,image/jpeg,image/gif,image/webp"
                    onChange={handleFileChange}
                  />
                </label>
              </section>

              <section className="client-profile-form-grid">
                <label>
                  <span>Full name</span>
                  <input type="text" name="full_name" required defaultValue={profile.fullName || ''} />
                </label>
                <label>
                  <span>Email</span>
                  <input type="email" name="email" required defaultValue={profile.email || ''} />
                </label>
                <label>
                  <span>Contact number</span>
                  <input type="text" name="contact_number" required defaultValue={profile.contactNumber || ''} />
                </label>
                <label className="is-full">
                  <span>Address</span>
                  <textarea name="address" rows="4" defaultValue={profile.address || ''} />
                </label>
              </section>

              <section className="client-profile-security-panel">
                <div className="client-profile-security-copy">
                  <div className="client-profile-security-icon">
                    <Icon name="shield" />
                  </div>
                  <div>
                    <strong>Security checkpoint</strong>
                    <p>Current password is required before email credentials or password details can be changed.</p>
                  </div>
                </div>
                <div className="client-profile-form-grid">
                  <label>
                    <span>Current password</span>
                    <div className="password-field" data-password-toggle>
                      <input type="password" name="current_password" autoComplete="current-password" placeholder="Required for sensitive changes" />
                      <button type="button" className="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password">
                        <span className="sr-only">Show password</span>
                      </button>
                    </div>
                  </label>
                  <label>
                    <span>New password</span>
                    <div className="password-field" data-password-toggle>
                      <input type="password" name="new_password" minLength="10" autoComplete="new-password" placeholder="At least 10 characters" />
                      <button type="button" className="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password">
                        <span className="sr-only">Show password</span>
                      </button>
                    </div>
                  </label>
                  <label>
                    <span>Confirm new password</span>
                    <div className="password-field" data-password-toggle>
                      <input type="password" name="confirm_password" autoComplete="new-password" placeholder="Repeat the new password" />
                      <button type="button" className="password-toggle" data-password-toggle-button aria-pressed="false" aria-label="Show password" title="Show password">
                        <span className="sr-only">Show password</span>
                      </button>
                    </div>
                  </label>
                </div>
              </section>

              <div className="client-profile-form-actions">
                <button className="button button-primary" type="submit" disabled={isSubmitting}>
                  {isSubmitting ? 'Saving...' : 'Save record'}
                </button>
                <button className="button button-secondary" type="button" onClick={onClose} disabled={isSubmitting}>
                  Cancel
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    );
  }

  function App() {
    const [isEditorOpen, setIsEditorOpen] = useState(Boolean(ui.openEditor));
    const addressLabel = (profile.address || '').trim() || 'Add your address to complete your contact profile.';
    const riskTone = riskToneMap[String(profile.riskLevel || 'low').toLowerCase()] || 'calm';

    return (
      <>
        <div className="client-profile-hero">
          <section className="client-profile-panel client-profile-panel--identity">
            <div className="client-profile-identity-top">
              <div className="client-profile-badge-row">
                <span className="client-profile-kicker">Client profile</span>
                <span className={`client-profile-risk-pill tone-${riskTone}`}>
                  {riskLabel(profile.riskLevel)} risk
                </span>
              </div>
              <Avatar
                src={profile.avatarUrl || ''}
                initials={profile.initials || 'CL'}
                alt={`Avatar for ${profile.fullName || 'Client'}`}
                large
              />
            </div>

            <div className="client-profile-identity-copy">
              <h2>{profile.fullName || 'Client'}</h2>
              <p>Maintain accurate account information for case coordination, identity verification, and billing records.</p>
            </div>

            <div className="client-profile-meta-strip" aria-label="Account summary">
              <div className="client-profile-meta-item">
                <span>Role</span>
                <strong>Client</strong>
              </div>
              <div className="client-profile-meta-item">
                <span>Member since</span>
                <strong>{profile.memberSince || 'Recently joined'}</strong>
              </div>
              <div className="client-profile-meta-item">
                <span>Risk level</span>
                <strong>{riskLabel(profile.riskLevel)}</strong>
              </div>
            </div>

            <div className="client-profile-cta-row">
              <button className="button button-primary" type="button" onClick={() => setIsEditorOpen(true)}>
                <Icon name="edit" />
                <span>Update record</span>
              </button>
            </div>
          </section>
        </div>

        <div className="client-profile-content-grid">
          <section className="client-profile-panel">
            <div className="client-profile-section-head">
              <div>
                <span className="client-profile-kicker">Client information</span>
                <h3>Official account record</h3>
              </div>
            </div>

            <div className="client-profile-detail-grid">
              <DetailCard icon="user" label="Client name" value={profile.fullName || 'Not provided'} hint="Registered account holder name." />
              <DetailCard icon="mail" label="Email address" value={profile.email || 'Not provided'} hint="Used for sign-in and account notices." />
              <DetailCard icon="phone" label="Contact number" value={profile.contactNumber || 'Not provided'} hint="Visible for appointment follow-ups and billing support." />
              <DetailCard icon="location" label="Address" value={profile.address || 'Not provided'} hint="Useful for records, invoices, and client identification." />
              <DetailCard icon="calendar" label="Member since" value={profile.memberSince || 'Recently joined'} hint="Account registration period." />
            </div>
          </section>
        </div>

        <ProfileEditor isOpen={isEditorOpen} onClose={() => setIsEditorOpen(false)} />
      </>
    );
  }

  ReactDOM.createRoot(mountNode).render(<App />);
})();
