(() => {
  const { useEffect, useState } = React;
  const { createPortal } = ReactDOM;

  const sampleLawyers = [
    {
      id: 901,
      name: 'Maya Santos',
      specialization: 'Corporate Law',
      status: 'active',
      rating: 5,
      reviewsCount: 18,
      barRoll: 'BAR-2021-10452',
      bio: 'Corporate counsel focused on contracts, governance, and fast-moving commercial work with calm, practical guidance.',
      avatarUrl: '',
      appointUrl: '#',
      viewProfileUrl: '#',
      canAppoint: true,
      review: { rating: 0, comment: '' },
    },
    {
      id: 902,
      name: 'Ethan Reyes',
      specialization: 'Litigation',
      status: 'inactive',
      rating: 4.7,
      reviewsCount: 0,
      barRoll: 'BAR-2019-08731',
      bio: 'Trial lawyer with a sharp eye for dispute strategy, evidence review, and client-friendly communication.',
      avatarUrl: '',
      appointUrl: '#',
      viewProfileUrl: '#',
      canAppoint: false,
      review: { rating: 0, comment: '' },
    },
    {
      id: 903,
      name: 'Sofia Tan',
      specialization: 'Family Law',
      status: 'active',
      rating: 4.9,
      reviewsCount: 6,
      barRoll: 'BAR-2020-22118',
      bio: 'Supportive family law attorney who balances empathy, precision, and strong case organization.',
      avatarUrl: '',
      appointUrl: '#',
      viewProfileUrl: '#',
      canAppoint: true,
      review: { rating: 0, comment: '' },
    },
  ];

  const pageDataNode = document.getElementById('lawyers-app-data');
  const mountNode = document.getElementById('lawyers-app');
  if (!pageDataNode || !mountNode || !window.ReactDOM) {
    return;
  }

  if (window.__lexLawyersRoot && typeof window.__lexLawyersRoot.unmount === 'function') {
    window.__lexLawyersRoot.unmount();
  }
  mountNode.innerHTML = '';

  const staleModalRoots = document.querySelectorAll('[data-lawyer-review-modal-root]');
  staleModalRoots.forEach((node) => node.remove());

  const ensureModalRoot = () => {
    const existing = document.querySelector('[data-lawyer-review-modal-root]');
    if (existing) {
      return existing;
    }
    const node = document.createElement('div');
    node.setAttribute('data-lawyer-review-modal-root', 'true');
    document.body.appendChild(node);
    return node;
  };

  const pageData = JSON.parse(pageDataNode.textContent || '{}');
  const initialLawyers = Array.isArray(pageData.lawyers) && pageData.lawyers.length > 0
    ? pageData.lawyers
    : pageData.useSampleFallback ? sampleLawyers : [];

  const formatRating = (value) => Number(value || 0).toFixed(1);
  const normalizeStatus = (value) => String(value || 'inactive').toLowerCase();
  const statusLabels = {
    active: 'Accepting appointments',
    busy: 'Currently busy',
    inactive: 'Inactive',
    suspended: 'Suspended',
  };
  const statusLabel = (value) => statusLabels[normalizeStatus(value)] || String(value || 'Unknown');
  const formatRollNumber = (value) => {
    const text = String(value || '').trim();
    if (!text) {
      return 'N/A';
    }
    const groups = text.match(/\d+/g);
    return groups?.length ? groups[groups.length - 1] : text;
  };
  const initialsFromName = (name) => {
    const compact = String(name || 'LW').replace(/\s+/g, '');
    return (compact.slice(0, 2) || 'LW').toUpperCase();
  };

  function RatingStars({
    value,
    max = 5,
    interactive = false,
    highlightedValue = null,
    onSelect = null,
    onHover = null,
    onClear = null,
    ariaLabel = 'Rating',
  }) {
    const activeValue = highlightedValue ?? value;

    return (
      <div
        className={`rating-stars ${interactive ? 'is-interactive' : 'is-readonly'}`}
        role={interactive ? 'radiogroup' : 'img'}
        aria-label={ariaLabel}
      >
        {Array.from({ length: max }, (_, index) => {
          const starValue = index + 1;
          const filled = starValue <= activeValue;
          return interactive ? (
            <button
              key={starValue}
              type="button"
              className={`rating-star ${filled ? 'is-filled' : ''}`}
              onClick={() => onSelect?.(starValue)}
              onMouseEnter={() => onHover?.(starValue)}
              onFocus={() => onHover?.(starValue)}
              onMouseLeave={() => onClear?.()}
              onBlur={() => onClear?.()}
              aria-label={`Rate ${starValue} star${starValue === 1 ? '' : 's'}`}
              aria-pressed={value === starValue}
            >
              {"\u2605"}
            </button>
          ) : (
            <span
              key={starValue}
              className={`rating-star ${filled ? 'is-filled' : ''}`}
              aria-hidden="true"
            >
              {"\u2605"}
            </span>
          );
        })}
      </div>
    );
  }

  function ReviewForm({ lawyer, csrfToken, reviewEndpoint, onSaved, demoMode = false, onClose = null, showHeader = true }) {
    const [rating, setRating] = useState(lawyer.review?.rating || 0);
    const [comment, setComment] = useState(lawyer.review?.comment || '');
    const [hoverRating, setHoverRating] = useState(null);
    const [status, setStatus] = useState(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
      setRating(lawyer.review?.rating || 0);
      setComment(lawyer.review?.comment || '');
      setHoverRating(null);
      setIsSubmitting(false);
    }, [lawyer.id, lawyer.review?.rating, lawyer.review?.comment]);

    const submitLabel = (lawyer.review?.rating || 0) > 0 ? 'Update Review' : 'Save Review';

    const handleSubmit = async (event) => {
      event.preventDefault();
      if (demoMode) {
        setStatus({ type: 'error', message: 'Preview profiles cannot save reviews yet.' });
        return;
      }
      if (!rating) {
        setStatus({ type: 'error', message: 'Select a rating before saving your review.' });
        return;
      }

      setIsSubmitting(true);
      setStatus(null);

      try {
        const response = await fetch(reviewEndpoint, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: new URLSearchParams({
            action: 'review',
            lawyer_id: String(lawyer.id),
            rating: String(rating),
            comment,
            csrf_token: csrfToken,
          }).toString(),
        });

        const data = await response.json();
        if (!response.ok || !data.ok) {
          throw new Error(data.message || 'Unable to save the review right now.');
        }

        if (data.lawyer) {
          onSaved?.(data.lawyer, data.message || 'Review saved.');
        }
        setStatus({ type: 'success', message: data.message || 'Review saved.' });
        if (onClose) {
          window.setTimeout(() => onClose(), 150);
        }
      } catch (error) {
        setStatus({ type: 'error', message: error.message || 'Unable to save the review right now.' });
      } finally {
        setIsSubmitting(false);
      }
    };

    return (
      <form className="review-form" data-no-loading onSubmit={handleSubmit}>
        <input type="hidden" name="lawyer_id" value={lawyer.id} />
        {showHeader ? (
          <div className="review-form__header">
            <div>
              <h4>Rating & Review</h4>
              <p>Share feedback about professionalism, communication, or results.</p>
            </div>
            <span className="review-form__meta">
              {lawyer.reviewsCount > 0 ? `${lawyer.reviewsCount} review${lawyer.reviewsCount === 1 ? '' : 's'}` : 'No reviews yet'}
            </span>
          </div>
        ) : null}

        <div className="review-form__rating">
          <RatingStars
            value={rating}
            interactive
            highlightedValue={hoverRating ?? rating}
            onSelect={(nextValue) => {
              setRating(nextValue);
              setStatus(null);
            }}
            onHover={setHoverRating}
            onClear={() => setHoverRating(null)}
            ariaLabel={`Rate ${lawyer.name}`}
          />
          <span className="review-form__rating-label">
            {rating ? `${rating}.0 out of 5 selected` : 'Tap a star to choose a rating'}
          </span>
        </div>

        <label className="review-form__field">
          <span className="sr-only">Comment</span>
          <textarea
            rows="4"
            value={comment}
            onChange={(event) => {
              setComment(event.target.value);
              setStatus(null);
            }}
            placeholder="Share feedback about professionalism, communication, or results."
          />
        </label>

        <div className="review-form__footer">
          <button
            type="submit"
            className="button button-primary review-form__submit"
            disabled={!rating || isSubmitting || demoMode}
          >
            {demoMode ? 'Preview only' : isSubmitting ? 'Saving...' : submitLabel}
          </button>
          {status ? (
            <div className={`inline-status inline-status--${status.type}`} role="status" aria-live="polite">
              {status.message}
            </div>
          ) : null}
        </div>
      </form>
    );
  }

  function ReviewModal({ isOpen, lawyer, csrfToken, reviewEndpoint, onSaved, onClose, demoMode = false }) {
    useEffect(() => {
      if (!isOpen) {
        return;
      }

      const onKeyDown = (event) => {
        if (event.key === 'Escape') {
          onClose?.();
        }
      };

      document.addEventListener('keydown', onKeyDown);
      document.body.classList.add('has-modal-open');

      return () => {
        document.removeEventListener('keydown', onKeyDown);
        document.body.classList.remove('has-modal-open');
      };
    }, [isOpen, onClose]);

    if (!isOpen) {
      return null;
    }

    return createPortal(
      <div
        className="review-modal"
        role="presentation"
        onMouseDown={(event) => {
          if (event.target === event.currentTarget) {
            onClose?.();
          }
        }}
      >
        <div
          className="review-modal__dialog"
          role="dialog"
          aria-modal="true"
          aria-labelledby={`review-modal-title-${lawyer.id}`}
          onMouseDown={(event) => {
            event.stopPropagation();
          }}
          onClick={(event) => {
            event.stopPropagation();
          }}
        >
          <div className="review-modal__header">
            <div>
              <h4 id={`review-modal-title-${lawyer.id}`}>{(lawyer.review?.rating || 0) > 0 ? 'Update Review' : 'Rating & Review'}</h4>
              <p>Share feedback about professionalism, communication, or results.</p>
            </div>
            <button type="button" className="review-modal__close" onClick={() => onClose?.()} aria-label="Close review form">
              {"\u2715"}
            </button>
          </div>

          <ReviewForm
            lawyer={lawyer}
            csrfToken={csrfToken}
            reviewEndpoint={reviewEndpoint}
            onSaved={onSaved}
            demoMode={demoMode}
            onClose={onClose}
            showHeader={false}
          />
        </div>
      </div>,
      ensureModalRoot()
    );
  }

  function LawyerCard({ lawyer, onOpenReview, demoMode = false }) {
    const status = normalizeStatus(lawyer.status);
    const nameInitials = initialsFromName(lawyer.name);
    const reviewValue = Number(lawyer.rating || 0);
    const reviewsCount = Number(lawyer.reviewsCount || 0);
    const hasReviews = reviewsCount > 0;
    const reviewButtonLabel = hasReviews ? 'Update Review' : 'Rate & Review';
    const handleReviewTriggerClick = (event) => {
      event.stopPropagation();
      onOpenReview?.(lawyer);
    };

    return (
      <article className="lawyer-card">
        <div className="lawyer-card__media">
          {lawyer.avatarUrl ? (
            <img src={lawyer.avatarUrl} alt={`Avatar for ${lawyer.name}`} />
          ) : (
            <div className="lawyer-card__placeholder" aria-hidden="true">
              <span className="lawyer-card__person-icon" />
              <span className="lawyer-card__initials">{nameInitials}</span>
            </div>
          )}
          <span className={`status-badge is-${status}`}>{statusLabel(status)}</span>
        </div>

        <div className="lawyer-card__body">
          <div className="lawyer-card__identity">
            <h3>{lawyer.name}</h3>
            <p><span aria-hidden="true">{"\u229F"}</span> {lawyer.specialization || 'General Practice'}</p>
          </div>

          <button
            type="button"
            className="lawyer-card__rating lawyer-card__rating-trigger"
            onClick={handleReviewTriggerClick}
            aria-haspopup="dialog"
            aria-expanded="false"
            aria-label={`${reviewButtonLabel} for ${lawyer.name}`}
          >
            <RatingStars
              value={reviewValue}
              ariaLabel={`${lawyer.name} average rating ${formatRating(reviewValue)} out of 5`}
            />
            <span>{formatRating(reviewValue)} - {reviewsCount.toLocaleString()} review{reviewsCount === 1 ? '' : 's'}</span>
          </button>

          <div className="lawyer-card__chips">
            <span>Roll {formatRollNumber(lawyer.barRoll)}</span>
            <span>{lawyer.joinedLabel || 'May 2026'}</span>
          </div>

          <div className="lawyer-card__actions">
            {demoMode ? (
              <button className="button button-secondary" type="button" disabled aria-disabled="true" title="Preview profiles are not linked yet">
                Profile
              </button>
            ) : (
              <a className="button button-secondary" href={lawyer.viewProfileUrl}>
                Profile
              </a>
            )}
            {lawyer.canAppoint && !demoMode ? (
              <a className="button button-primary" href={lawyer.appointUrl}>
                Book
              </a>
            ) : (
              <button
                className="button button-primary"
                type="button"
                disabled
                aria-disabled="true"
                title={demoMode ? 'Preview profiles are not linked yet' : status === 'busy' ? 'This lawyer is currently busy' : 'This lawyer is not accepting appointments'}
              >
                Book
              </button>
            )}
          </div>
        </div>
      </article>
    );
  }

  function LawyerList() {
    const [lawyers, setLawyers] = useState(initialLawyers);
    const initialSearch = pageData.search || '';
    const [searchDraft, setSearchDraft] = useState(initialSearch);
    const [activeSearch, setActiveSearch] = useState(initialSearch);
    const [specialization, setSpecialization] = useState(pageData.specialization || '');
    const [availability, setAvailability] = useState('all');
    const [sortBy, setSortBy] = useState('name');
    const [toast, setToast] = useState(null);
    const [activeReviewLawyerId, setActiveReviewLawyerId] = useState(null);

    useEffect(() => {
      if (!toast) {
        return;
      }
      const timer = window.setTimeout(() => setToast(null), 5600);
      return () => window.clearTimeout(timer);
    }, [toast]);


    const handleReviewSaved = (updatedLawyer, message) => {
      setLawyers((current) =>
        current.map((lawyer) => (lawyer.id === updatedLawyer.id ? updatedLawyer : lawyer))
      );
      setActiveReviewLawyerId(null);
      if (typeof window.showNotification === 'function') {
        window.showNotification('success', message);
        setToast(null);
        return;
      }
      setToast({ type: 'success', message });
    };

    const hasLiveLawyers = Array.isArray(pageData.lawyers) && pageData.lawyers.length > 0;
    const showSampleNote = !hasLiveLawyers && pageData.useSampleFallback;
    const activeReviewLawyer = lawyers.find((lawyer) => lawyer.id === activeReviewLawyerId) || null;
    const specializations = Array.isArray(pageData.specializations) ? pageData.specializations : [];
    const normalizedSearch = activeSearch.trim().toLowerCase();
    const filteredLawyers = lawyers.filter((lawyer) => {
      const matchesSpecialization = !specialization || lawyer.specialization === specialization;
      const matchesAvailability = availability === 'all'
        || (availability === 'available' && lawyer.canAppoint)
        || (availability === 'reviewed' && Number(lawyer.reviewsCount || 0) > 0);
      const haystack = [
        lawyer.name,
        lawyer.specialization,
        lawyer.barRoll,
        lawyer.bio,
      ].join(' ').toLowerCase();
      return matchesSpecialization && matchesAvailability && (!normalizedSearch || haystack.includes(normalizedSearch));
    }).sort((a, b) => {
      if (sortBy === 'rating') {
        return Number(b.rating || 0) - Number(a.rating || 0) || String(a.name).localeCompare(String(b.name));
      }
      if (sortBy === 'reviews') {
        return Number(b.reviewsCount || 0) - Number(a.reviewsCount || 0) || Number(b.rating || 0) - Number(a.rating || 0);
      }
      if (sortBy === 'availability') {
        return Number(Boolean(b.canAppoint)) - Number(Boolean(a.canAppoint)) || String(a.name).localeCompare(String(b.name));
      }
      return String(a.name).localeCompare(String(b.name));
    });

    const handleSearchSubmit = (event) => {
      event.preventDefault();
      setActiveSearch(searchDraft);
    };

    const handleClear = () => {
      setSearchDraft('');
      setActiveSearch('');
      setSpecialization('');
      setAvailability('all');
      setSortBy('name');
    };

    return (
      <div className="lawyer-directory-shell">
        <form className="lawyer-searchbar" data-no-loading onSubmit={handleSearchSubmit}>
          <label className="lawyer-searchbar__field">
            <span>Search</span>
            <input
              type="search"
              value={searchDraft}
              onChange={(event) => setSearchDraft(event.target.value)}
              placeholder="Name, specialization, bar number, or background"
            />
          </label>
          <label className="lawyer-searchbar__field lawyer-searchbar__field--select">
            <span>Specialization</span>
            <select value={specialization} onChange={(event) => setSpecialization(event.target.value)}>
              <option value="">All specializations</option>
              {specializations.map((item) => (
                <option key={item} value={item}>{item}</option>
              ))}
            </select>
          </label>
          <label className="lawyer-searchbar__field lawyer-searchbar__field--select">
            <span>Sort</span>
            <select value={sortBy} onChange={(event) => setSortBy(event.target.value)}>
              <option value="name">Name</option>
              <option value="availability">Availability</option>
              <option value="rating">Highest rating</option>
              <option value="reviews">Most reviewed</option>
            </select>
          </label>
          <div className="lawyer-searchbar__actions">
            <button className="button button-primary" type="submit">Search</button>
            <button className="button button-secondary" type="button" onClick={handleClear}>Clear</button>
          </div>
        </form>

        <div className="lawyer-directory-toolbar" aria-label="Lawyer result filters">
          <div className="lawyer-filter-chips">
            {[
              ['all', 'All'],
              ['available', 'Accepting appointments'],
              ['reviewed', 'Reviewed'],
            ].map(([value, label]) => (
              <button
                key={value}
                type="button"
                className={`lawyer-filter-chip ${availability === value ? 'is-active' : ''}`}
                onClick={() => setAvailability(value)}
                aria-pressed={availability === value}
              >
                {label}
              </button>
            ))}
          </div>
          <span className="lawyer-result-count">
            {filteredLawyers.length.toLocaleString()} of {lawyers.length.toLocaleString()} lawyers
          </span>
        </div>

        {showSampleNote ? (
          <div className="inline-banner" role="status">
            Showing sample lawyer profiles until live records are available.
          </div>
        ) : null}

        {toast ? (
          <div
            className={`toast toast-${toast.type} is-visible`}
            role={toast.type === 'error' ? 'alert' : 'status'}
            aria-live="polite"
            aria-atomic="true"
            data-toast
            data-toast-ready="true"
            data-toast-type={toast.type}
            data-dismiss-after="5600"
            style={{ '--toast-duration': '5600ms' }}
          >
            <span className="toast-icon" aria-hidden="true"></span>
            <span className="toast-content">
              <strong className="toast-title">{toast.type === 'error' ? 'Error' : 'Success'}</strong>
              <span className="toast-message">{toast.message}</span>
            </span>
            <button className="toast-close" type="button" aria-label="Dismiss notification" onClick={() => setToast(null)}>&times;</button>
            <span className="toast-progress" aria-hidden="true"></span>
          </div>
        ) : null}

        {filteredLawyers.length ? (
          <div className="lawyer-directory-grid">
            {filteredLawyers.map((lawyer) => (
            <LawyerCard
              key={lawyer.id}
              lawyer={lawyer}
              onOpenReview={(selectedLawyer) => setActiveReviewLawyerId(selectedLawyer.id)}
              demoMode={showSampleNote}
            />
            ))}
          </div>
        ) : (
          <div className="empty-state empty-state--lawyers">
            <h3>No lawyers match this filter</h3>
            <p>Try a different specialization or clear the search to see every lawyer in the directory.</p>
          </div>
        )}

        <ReviewModal
          isOpen={Boolean(activeReviewLawyer)}
          lawyer={activeReviewLawyer || initialLawyers[0]}
          csrfToken={pageData.csrfToken}
          reviewEndpoint={pageData.reviewEndpoint}
          onSaved={handleReviewSaved}
          onClose={() => setActiveReviewLawyerId(null)}
          demoMode={showSampleNote}
        />
      </div>
    );
  }

  window.__lexLawyersRoot = ReactDOM.createRoot(mountNode);
  window.__lexLawyersRoot.render(<LawyerList />);
})();
