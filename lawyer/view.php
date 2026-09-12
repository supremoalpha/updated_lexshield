<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Public attorney record. Guests can view this page without login or register.
$user = lex_current_user();
$lawyerId = lex_sanitize_int($_GET['id'] ?? 0);
$isClientUser = $user && (($user['role'] ?? '') === 'client');
$clientId = $isClientUser ? lex_user_client_id((int) $user['id']) : 0;
$message = '';
$error = '';
$isJsonRequest = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || str_contains(strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')), 'xmlhttprequest');

$lawyer = null;
if ($lawyerId > 0) {
    $query = 'SELECT l.id, l.bar_number, l.specialization, l.status, l.bio, l.background, l.contact_number, u.full_name, u.email, u.avatar_stored_name, u.created_at,
                COALESCE(stats.avg_rating, 0) AS avg_rating,
                COALESCE(stats.review_count, 0) AS review_count';
    if ($isClientUser) {
        $query .= ',
                my.rating AS my_rating,
                my.comment AS my_comment';
    }
    $query .= '
         FROM lawyers l
         JOIN users u ON u.id = l.user_id
         LEFT JOIN (
            SELECT lawyer_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
            FROM lawyer_reviews
            GROUP BY lawyer_id
         ) stats ON stats.lawyer_id = l.id';
    if ($isClientUser) {
        $query .= '
         LEFT JOIN lawyer_reviews my ON my.lawyer_id = l.id AND my.client_id = :client_id';
    }
    $query .= '
         WHERE l.id = :id
           AND u.is_active = 1
           AND l.status IN ("active", "busy")
         LIMIT 1';

    $params = ['id' => $lawyerId];
    if ($isClientUser) {
        $params['client_id'] = $clientId;
    }
    $rows = lex_recent($query, $params);
    $lawyer = $rows[0] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isClientUser) {
        $error = 'Only clients can submit a review.';
    } elseif (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('lawyer/view.php');
        $error = 'Invalid CSRF token.';
    } elseif ((string) ($_POST['action'] ?? '') !== 'review') {
        $error = 'Unsupported action.';
    } else {
        $postedLawyerId = lex_sanitize_int($_POST['lawyer_id'] ?? 0);
        $rating = lex_sanitize_int($_POST['rating'] ?? 0);
        $comment = lex_sanitize_text($_POST['comment'] ?? '');

        if ($postedLawyerId !== $lawyerId || !$lawyer) {
            $error = 'Choose a lawyer first.';
        } elseif ($rating < 1 || $rating > 5) {
            $error = 'Choose a rating from 1 to 5.';
        } else {
            $stmt = lex_pdo()->prepare(
                'INSERT INTO lawyer_reviews (lawyer_id, client_id, rating, comment)
                 VALUES (:lawyer_id, :client_id, :rating, :comment)
                 ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), updated_at = NOW()'
            );
            $stmt->execute([
                'lawyer_id' => $lawyerId,
                'client_id' => $clientId,
                'rating' => $rating,
                'comment' => $comment !== '' ? $comment : null,
            ]);
            lex_audit('rate_lawyer', 'lawyer_reviews', (string) $lawyerId);

            $updatedRows = lex_recent(
                'SELECT l.id, l.bar_number, l.specialization, l.status, l.bio, l.background, l.contact_number, u.full_name, u.email, u.avatar_stored_name, u.created_at,
                        COALESCE(stats.avg_rating, 0) AS avg_rating,
                        COALESCE(stats.review_count, 0) AS review_count,
                        my.rating AS my_rating,
                        my.comment AS my_comment
                 FROM lawyers l
                 JOIN users u ON u.id = l.user_id
                 LEFT JOIN (
                    SELECT lawyer_id, AVG(rating) AS avg_rating, COUNT(*) AS review_count
                    FROM lawyer_reviews
                    GROUP BY lawyer_id
                 ) stats ON stats.lawyer_id = l.id
                 LEFT JOIN lawyer_reviews my ON my.lawyer_id = l.id AND my.client_id = :client_id
                 WHERE l.id = :id
                   AND u.is_active = 1
                 LIMIT 1',
                ['id' => $lawyerId, 'client_id' => $clientId]
            );
            $lawyer = $updatedRows[0] ?? $lawyer;
            $message = 'Your review was saved.';
        }
    }

    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8', true, $error !== '' ? 422 : 200);
        echo json_encode([
            'ok' => $error === '',
            'message' => $error !== '' ? $error : $message,
            'stats' => [
                'rating' => round((float) ($lawyer['avg_rating'] ?? 0), 1),
                'review_count' => (int) ($lawyer['review_count'] ?? 0),
                'my_rating' => (int) ($lawyer['my_rating'] ?? 0),
                'my_comment' => (string) ($lawyer['my_comment'] ?? ''),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$reviews = $lawyerId > 0 ? lex_recent(
    'SELECT r.rating, r.comment, r.created_at, u.full_name AS client_name
     FROM lawyer_reviews r
     JOIN clients c ON c.id = r.client_id
     JOIN users u ON u.id = c.user_id
     WHERE r.lawyer_id = :id
     ORDER BY r.created_at DESC',
    ['id' => $lawyerId]
) : [];

$ratingValue = (float) ($lawyer['avg_rating'] ?? 0);
$totalReviews = (int) ($lawyer['review_count'] ?? count($reviews));
$filledStars = max(0, min(5, (int) round($ratingValue)));
$avatarUrl = $lawyer ? lex_profile_avatar_url((string) ($lawyer['avatar_stored_name'] ?? '')) : '';
$initials = $lawyer ? strtoupper(substr(preg_replace('/\s+/', '', (string) ($lawyer['full_name'] ?? 'LW')) ?: 'LW', 0, 2)) : 'LW';
$joinedDate = $lawyer ? strtotime((string) ($lawyer['created_at'] ?? '')) : false;
$joinedLabel = $joinedDate ? date('M Y', $joinedDate) : 'Recently joined';
$joinedHelper = $joinedDate ? date('F j, Y', $joinedDate) : 'Recently joined';
$statusLabel = $lawyer ? ucfirst((string) ($lawyer['status'] ?? 'active')) : 'Active';
$specialization = trim((string) ($lawyer['specialization'] ?? ''));
$lawyerBio = $lawyer ? trim((string) ($lawyer['bio'] ?? '')) : '';
$lawyerBackground = $lawyer ? trim((string) ($lawyer['background'] ?? '')) : '';
$fullName = $lawyer ? (string) $lawyer['full_name'] : 'Lawyer Profile';
$email = $lawyer ? (string) ($lawyer['email'] ?? '') : '';
$contactNumber = $lawyer ? trim((string) ($lawyer['contact_number'] ?? '')) : '';
$reviewValue = (int) ($lawyer['my_rating'] ?? 0);
$reviewComment = (string) ($lawyer['my_comment'] ?? '');
$ctaLink = $isClientUser
    ? lex_app_url('client/appointment.php?lawyer_id=' . $lawyerId)
    : lex_app_url('auth/register.php');
$defaultBackLink = lex_app_url('index.php');
$returnTo = (string) ($_GET['return_to'] ?? '');
$backLink = $defaultBackLink;
$backLinkLabel = 'Back to home';
if ($returnTo !== '') {
    $allowedBackLinks = [
        lex_app_url('index.php'),
        lex_app_url('client/lawyers.php'),
    ];

    foreach ($allowedBackLinks as $allowedBackLink) {
        if ($returnTo === $allowedBackLink || str_starts_with($returnTo, $allowedBackLink . '?')) {
            $backLink = $returnTo;
            $backLinkLabel = $allowedBackLink === lex_app_url('client/lawyers.php') ? 'Back to attorneys' : 'Back to home';
            break;
        }
    }
}

$maskReviewerName = static function (string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
        return 'Anonymous';
    }

    $parts = preg_split('/\s+/u', $name) ?: [];
    if (count($parts) === 1) {
        return preg_replace('/./u', '*', $parts[0]) ?: '*****';
    }

    $firstName = array_shift($parts);
    $lastName = (string) array_pop($parts);
    $maskedLastName = preg_replace('/./u', '*', $lastName) ?: '*****';

    return trim($firstName . ' ' . $maskedLastName);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, shrink-to-fit=no">
  <title><?= $lawyer ? lex_e($fullName) : 'Lawyer Profile' ?> | LEXSHIELD</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Fraunces:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= lex_e(lex_app_url('public/css/style.css')) ?>">
  <style>
    body {
      min-height: 100vh;
      background: #071423;
      color: #f7fbff;
    }

    .public-lawyer-shell {
      width: min(1080px, calc(100% - 1.25rem));
      margin: 0 auto;
      padding: 0.85rem 0 1.25rem;
    }

    .public-lawyer-layout,
    .public-lawyer-hero-main,
    .public-lawyer-info,
    .public-review-list {
      display: grid;
      gap: 0.75rem;
    }

    .public-lawyer-stage {
      display: grid;
      grid-template-columns: minmax(0, 1.42fr) minmax(268px, 0.78fr);
      gap: 0.85rem;
      align-items: stretch;
      min-width: 0;
    }

    .public-lawyer-panel {
      background: #0b1d32;
      border: 1px solid rgba(143, 165, 196, 0.2);
      border-radius: 8px;
      box-shadow: none;
      color: #f7fbff;
      min-width: 0;
    }

    .public-lawyer-main {
      padding: 1rem;
    }

    .public-lawyer-identity {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      gap: 0.8rem;
      align-items: start;
    }

    .public-lawyer-avatar-wrap {
      width: 54px;
      height: 54px;
      border-radius: 12px;
      background: #162a43;
      border: 1px solid rgba(143, 165, 196, 0.22);
      overflow: hidden;
      display: grid;
      place-items: center;
      color: #f0cf65;
      font-weight: 800;
      flex: 0 0 54px;
    }

    .public-lawyer-avatar-wrap img,
    .public-lawyer-portrait img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .public-lawyer-name,
    .public-lawyer-reviews-head h2 {
      margin: 0;
      color: #f7fbff;
      font-family: "Inter", sans-serif;
    }

    .public-lawyer-name {
      font-size: clamp(1.45rem, 2.6vw, 1.9rem);
      line-height: 1.08;
      letter-spacing: 0;
    }

    .public-lawyer-subtitle {
      margin: 0.18rem 0 0;
      color: #aebbd0;
      font-size: 0.9rem;
    }

    .public-lawyer-pills,
    .public-lawyer-stars,
    .public-lawyer-actions,
    .public-lawyer-reviews-head,
    .public-lawyer-review-head,
    .public-lawyer-reviewer,
    .public-lawyer-notice,
    .public-review-modal__footer {
      display: flex;
      align-items: center;
      gap: 0.55rem;
    }

    .public-lawyer-pills {
      margin-top: 0.5rem;
      flex-wrap: wrap;
      gap: 0.38rem;
    }

    .public-lawyer-pill,
    .public-lawyer-rating-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
      border-radius: 999px;
      font-weight: 800;
      white-space: nowrap;
    }

    .public-lawyer-pill {
      gap: 0.35rem;
      padding: 0.28rem 0.62rem;
      font-size: 0.7rem;
      border: 1px solid rgba(143, 165, 196, 0.22);
      background: #152941;
      color: #e9eef8;
    }

    .public-lawyer-pill.is-status,
    .public-lawyer-rating-pill {
      background: rgba(240, 207, 101, 0.16);
      color: #f0cf65;
      border: 1px solid rgba(228, 195, 94, 0.32);
    }

    .public-lawyer-divider {
      height: 1px;
      background: rgba(143, 165, 196, 0.18);
    }

    .public-lawyer-stats {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.55rem;
    }

    .public-lawyer-stat {
      min-height: 82px;
      padding: 0.72rem;
      border-radius: 8px;
      background: #10243b;
      border: 1px solid rgba(143, 165, 196, 0.18);
      display: grid;
      align-content: start;
      gap: 0.12rem;
    }

    .public-lawyer-stat-label {
      margin: 0;
      color: #93a3bb;
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .public-lawyer-stat strong {
      color: #f7fbff;
      font-size: 1.42rem;
      line-height: 1;
    }

    .public-lawyer-stat-note,
    .public-lawyer-reviewer span,
    .public-lawyer-reviews-head p,
    .public-lawyer-reviews-count,
    .public-lawyer-notice,
    .public-review-modal__hint,
    .public-review-modal__stars-note {
      color: #9fb0c8;
      font-size: 0.78rem;
    }

    .public-lawyer-stars {
      gap: 0.08rem;
      color: rgba(159, 176, 200, 0.42);
      font-size: 0.82rem;
    }

    .public-lawyer-stars .is-filled {
      color: #f0cf65;
    }

    .public-lawyer-summary,
    .public-lawyer-profile-text,
    .public-lawyer-review-text {
      margin: 0;
      color: #e9eef8;
      line-height: 1.55;
      font-size: 0.9rem;
      overflow-wrap: anywhere;
      word-break: break-word;
    }

    .public-lawyer-profile-sections {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.55rem;
      min-width: 0;
    }

    .public-lawyer-profile-section {
      min-width: 0;
      padding: 0.75rem;
      border-radius: 8px;
      background: #10243b;
      border: 1px solid rgba(143, 165, 196, 0.18);
      display: grid;
      align-content: start;
      gap: 0.36rem;
    }

    .public-lawyer-profile-section h2 {
      margin: 0;
      color: #9fb0c8;
      font-family: "Inter", sans-serif;
      font-size: 0.72rem;
      letter-spacing: 0.08em;
      line-height: 1.2;
      text-transform: uppercase;
    }

    .public-lawyer-actions {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.55rem;
    }

    .public-lawyer-actions .button,
    .public-lawyer-review-actions .button,
    .public-review-modal .button {
      min-height: 42px;
      padding: 0.62rem 0.9rem;
      border-radius: 999px;
      font-size: 0.9rem;
      font-weight: 700;
      box-shadow: none;
    }

    .public-lawyer-actions .button-primary {
      background: linear-gradient(135deg, #f0cf65, #d8ad3e);
      color: #071423;
      border: 0;
    }

    .public-lawyer-actions .button-secondary,
    .public-lawyer-review-actions .button,
    .public-review-modal .button-secondary {
      background: #152941;
      color: #f7fbff;
      border: 1px solid rgba(143, 165, 196, 0.22);
    }

    .public-lawyer-side {
      overflow: hidden;
      display: grid;
      grid-template-rows: minmax(212px, 1fr) auto;
    }

    .public-lawyer-portrait {
      min-height: 212px;
      background: #efe5d1;
      display: grid;
      place-items: center;
    }

    .public-lawyer-portrait-fallback {
      color: #13243f;
      display: grid;
      place-items: center;
    }

    .public-lawyer-portrait-fallback svg {
      width: 82px;
      height: 82px;
    }

    .public-lawyer-info {
      padding: 0.72rem;
      gap: 0.52rem;
    }

    .public-lawyer-info-row {
      display: grid;
      grid-template-columns: 34px minmax(0, 1fr);
      gap: 0.58rem;
      align-items: center;
      color: #e9eef8;
      font-size: 0.84rem;
      min-width: 0;
    }

    .public-lawyer-info-row > span:last-child {
      min-width: 0;
      overflow-wrap: anywhere;
      word-break: break-word;
    }

    .public-lawyer-info-icon {
      width: 34px;
      height: 34px;
      display: grid;
      place-items: center;
      border-radius: 8px;
      background: #152941;
      color: #f0cf65;
    }

    .public-lawyer-info-icon svg {
      width: 16px;
      height: 16px;
      display: block;
    }

    .public-lawyer-reviews {
      padding: 0.9rem;
      background: #08192d;
    }

    .public-lawyer-reviews-head {
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.55rem;
      gap: 0.7rem;
    }

    .public-lawyer-reviews-head h2 {
      font-size: 1.08rem;
      font-family: "Inter", sans-serif;
      letter-spacing: 0;
    }

    .public-lawyer-reviews-head p {
      margin: 0.12rem 0 0;
    }

    .public-review-list {
      gap: 0.45rem;
    }

    .public-review-list--scrollable {
      max-height: min(calc((5.35rem * 10) + (0.45rem * 9)), 74vh);
      overflow-y: auto;
      overscroll-behavior: contain;
      padding-right: 0.35rem;
      scrollbar-gutter: stable;
    }

    .public-review-list--scrollable:focus-visible {
      outline: 2px solid rgba(240, 207, 101, 0.45);
      outline-offset: 4px;
      border-radius: 8px;
    }

    .public-review-list--scrollable::-webkit-scrollbar {
      width: 8px;
    }

    .public-review-list--scrollable::-webkit-scrollbar-track {
      background: rgba(143, 165, 196, 0.08);
      border-radius: 999px;
    }

    .public-review-list--scrollable::-webkit-scrollbar-thumb {
      background: rgba(240, 207, 101, 0.45);
      border-radius: 999px;
    }

    .public-lawyer-review-card {
      min-height: 5.35rem;
      padding: 0.62rem 0.72rem;
      border-radius: 8px;
      border: 1px solid rgba(143, 165, 196, 0.16);
      background: #10243b;
      display: grid;
      gap: 0.28rem;
    }

    .public-lawyer-review-head {
      justify-content: space-between;
      align-items: center;
      gap: 0.65rem;
    }

    .public-lawyer-reviewer {
      min-width: 0;
      gap: 0.48rem;
    }

    .public-lawyer-reviewer-badge {
      width: 28px;
      height: 28px;
      border-radius: 8px;
      background: rgba(240, 207, 101, 0.14);
      color: #f0cf65;
      display: grid;
      place-items: center;
      font-size: 0.72rem;
      font-weight: 800;
      flex: 0 0 28px;
    }

    .public-lawyer-reviewer strong,
    .public-lawyer-reviewer span {
      display: block;
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .public-lawyer-reviewer strong {
      color: #f7fbff;
      font-size: 0.86rem;
    }

    .public-lawyer-rating-pill {
      padding: 0.24rem 0.5rem;
      font-size: 0.72rem;
    }

    .public-lawyer-review-text {
      color: #cdd7e6;
      font-size: 0.84rem;
    }

    @media (min-width: 720px) {
      .public-lawyer-review-card {
        grid-template-columns: minmax(190px, 0.34fr) 74px minmax(0, 1fr);
        align-items: center;
        column-gap: 0.75rem;
      }

      .public-lawyer-review-head,
      .public-lawyer-review-card > .public-lawyer-stars,
      .public-lawyer-review-text {
        min-width: 0;
      }

      .public-lawyer-review-card > .public-lawyer-stars {
        justify-content: center;
      }
    }

    .public-lawyer-review-actions {
      margin-top: 0.55rem;
    }

    .public-lawyer-notice {
      justify-content: flex-start;
      margin-top: 0.65rem;
      padding: 0.62rem 0.75rem;
      border-radius: 8px;
      border: 1px solid rgba(143, 165, 196, 0.18);
      background: #10243b;
    }

    .public-lawyer-notice.is-error {
      color: #f2bfbe;
      border-color: rgba(201, 98, 98, 0.32);
      background: rgba(155, 56, 56, 0.16);
    }

    .public-lawyer-notice.is-success {
      color: #d7efbe;
      border-color: rgba(152, 190, 103, 0.32);
      background: rgba(110, 142, 59, 0.16);
    }

    .public-review-modal[hidden] {
      display: none;
    }

    .public-review-modal {
      position: fixed;
      inset: 0;
      z-index: 1000;
      background: rgba(7, 20, 35, 0.74);
      display: grid;
      place-items: center;
      padding: 1rem;
    }

    .public-review-modal__dialog {
      width: min(540px, 100%);
      border-radius: 8px;
      border: 1px solid rgba(143, 165, 196, 0.22);
      background: #0b1d32;
      box-shadow: 0 28px 80px rgba(0, 0, 0, 0.34);
      padding: 1rem;
      display: grid;
      gap: 0.75rem;
    }

    .public-review-modal__head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
    }

    .public-review-modal__head h3 {
      margin: 0;
      color: #f7fbff;
      font-family: "Inter", sans-serif;
      font-size: 1.15rem;
    }

    .public-review-modal__head p {
      margin: 0.22rem 0 0;
    }

    .public-review-modal__close {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      border: 1px solid rgba(143, 165, 196, 0.22);
      background: #152941;
      color: #f7fbff;
      display: grid;
      place-items: center;
      cursor: pointer;
    }

    .public-review-modal__rating {
      display: grid;
      gap: 0.45rem;
    }

    .public-review-modal__stars {
      display: flex;
      gap: 0.3rem;
    }

    .public-review-modal__star {
      appearance: none;
      border: 0;
      background: none;
      color: rgba(159, 176, 200, 0.42);
      font-size: 1.45rem;
      line-height: 1;
      padding: 0.1rem;
      cursor: pointer;
    }

    .public-review-modal__star.is-filled {
      color: #f0cf65;
    }

    .public-review-modal textarea {
      width: 100%;
      min-height: 112px;
      resize: vertical;
      border-radius: 8px;
      border: 1px solid rgba(143, 165, 196, 0.22);
      background: #10243b;
      color: #f7fbff;
      padding: 0.85rem 0.95rem;
    }

    .public-review-modal textarea:focus {
      outline: 2px solid rgba(201, 168, 76, 0.16);
      border-color: rgba(201, 168, 76, 0.42);
    }

    .public-review-modal__footer {
      justify-content: flex-start;
      flex-wrap: wrap;
    }

    .public-review-modal__status {
      padding: 0.4rem 0.65rem;
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 700;
    }

    .public-review-modal__status.is-error {
      color: #f2bfbe;
      background: rgba(155, 56, 56, 0.18);
      border: 1px solid rgba(201, 98, 98, 0.24);
    }

    .public-review-modal__status.is-success {
      color: #d7efbe;
      background: rgba(110, 142, 59, 0.18);
      border: 1px solid rgba(152, 190, 103, 0.24);
    }

    .public-lawyer-empty {
      padding: 1.5rem;
    }

    .public-lawyer-empty .hero-badge {
      width: fit-content;
      background: rgba(240, 207, 101, 0.14);
      border-color: rgba(228, 195, 94, 0.32);
      color: #f0cf65;
    }

    .public-lawyer-empty h1 {
      color: #f7fbff;
    }

    .public-lawyer-empty .muted {
      color: #9fb0c8;
    }

    .public-lawyer-empty .button-primary {
      background: linear-gradient(135deg, #f0cf65, #d8ad3e);
      color: #071423;
      border: 0;
    }

    .public-lawyer-empty .button-secondary {
      background: #152941;
      color: #f7fbff;
      border-color: rgba(143, 165, 196, 0.22);
    }

    @media (max-width: 860px) {
      .public-lawyer-shell {
        width: calc(100% - 1rem);
        padding-left: max(0.35rem, env(safe-area-inset-left));
        padding-right: max(0.35rem, env(safe-area-inset-right));
      }

      .public-lawyer-stage,
      .public-lawyer-stats,
      .public-lawyer-profile-sections {
        grid-template-columns: 1fr;
      }

      .public-lawyer-pills,
      .public-lawyer-actions,
      .public-lawyer-reviews-head {
        flex-wrap: wrap;
      }
    }

    @media (max-width: 640px) {
      .public-lawyer-main,
      .public-lawyer-reviews,
      .public-review-modal__dialog {
        padding: 0.85rem;
        max-width: 100%;
      }

      .public-lawyer-identity,
      .public-lawyer-stats,
      .public-lawyer-actions,
      .public-lawyer-profile-sections {
        grid-template-columns: 1fr;
      }

      .public-lawyer-reviews-head,
      .public-lawyer-review-head,
      .public-review-modal__head,
      .public-review-modal__footer {
        display: grid;
      }

      .public-lawyer-review-head {
        justify-items: start;
      }

      .public-lawyer-actions .button,
      .public-lawyer-review-actions .button {
        width: 100%;
        min-height: 44px;
      }

      .public-review-modal {
        padding: 0.75rem;
      }

      .public-review-modal__dialog {
        width: min(100%, calc(100vw - 1.5rem));
        max-height: calc(100dvh - 1.5rem);
        overflow: auto;
      }
    }
  </style>
  <script defer src="<?= lex_e(lex_app_url('public/js/main.js')) ?>"></script>
</head>
<body>
  <main class="public-lawyer-shell">
    <section class="public-lawyer-layout">
      <?php if (!$lawyer): ?>
        <div class="public-lawyer-panel public-lawyer-empty">
          <div class="hero-badge">LEXSHIELD Lawyer</div>
          <h1>Lawyer not found.</h1>
          <p class="muted">The lawyer you selected may be inactive or no longer available.</p>
          <div class="landing-hero__actions">
            <a class="button button-primary" href="<?= lex_e($backLink) ?>"><?= lex_e($backLinkLabel) ?></a>
            <a class="button button-secondary" href="<?= lex_e($user ? lex_app_url($user['role'] . '/index.php') : lex_app_url('auth/login.php')) ?>">Open portal</a>
          </div>
        </div>
      <?php else: ?>
        <section class="public-lawyer-stage">
          <article class="public-lawyer-panel public-lawyer-main">
            <div class="public-lawyer-hero-main">
              <div class="public-lawyer-identity">
                <div class="public-lawyer-avatar-wrap">
                  <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= lex_e($avatarUrl) ?>" alt="Avatar for <?= lex_e($fullName) ?>">
                  <?php else: ?>
                    <span><?= lex_e($initials) ?></span>
                  <?php endif; ?>
                </div>

                <div>
                  <h1 class="public-lawyer-name"><?= lex_e($fullName) ?></h1>
                  <p class="public-lawyer-subtitle"><?= lex_e($specialization !== '' ? $specialization : 'Legal services') ?></p>
                  <div class="public-lawyer-pills">
                    <span class="public-lawyer-pill is-status"><?= lex_e($statusLabel) ?></span>
                    <span class="public-lawyer-pill">Roll No. <?= lex_e((string) $lawyer['bar_number']) ?></span>
                  </div>
                </div>
              </div>

              <div class="public-lawyer-divider" aria-hidden="true"></div>

              <div class="public-lawyer-stats">
                <div class="public-lawyer-stat">
                  <p class="public-lawyer-stat-label">Rating</p>
                  <strong id="profile-rating-value"><?= number_format($ratingValue, 1) ?></strong>
                  <div class="public-lawyer-stars" id="profile-rating-stars" aria-label="<?= number_format($ratingValue, 1) ?> out of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                      <span class="<?= $i <= $filledStars ? 'is-filled' : '' ?>"><?= $i <= $filledStars ? '&#9733;' : '&#9734;' ?></span>
                    <?php endfor; ?>
                  </div>
                </div>

                <div class="public-lawyer-stat">
                  <p class="public-lawyer-stat-label">Reviews</p>
                  <strong id="profile-review-count"><?= number_format($totalReviews) ?></strong>
                  <p class="public-lawyer-stat-note">Client review<?= $totalReviews === 1 ? '' : 's' ?></p>
                </div>

                <div class="public-lawyer-stat">
                  <p class="public-lawyer-stat-label">Member Since</p>
                  <strong><?= lex_e($joinedLabel) ?></strong>
                  <p class="public-lawyer-stat-note"><?= lex_e($joinedHelper) ?></p>
                </div>
              </div>

              <div class="public-lawyer-profile-sections">
                <section class="public-lawyer-profile-section" aria-labelledby="lawyer-bio-heading">
                  <h2 id="lawyer-bio-heading">Bio</h2>
                  <p class="public-lawyer-profile-text"><?= lex_e($lawyerBio !== '' ? $lawyerBio : 'No biography has been added yet.') ?></p>
                </section>

                <section class="public-lawyer-profile-section" aria-labelledby="lawyer-background-heading">
                  <h2 id="lawyer-background-heading">Background</h2>
                  <p class="public-lawyer-profile-text"><?= lex_e($lawyerBackground !== '' ? $lawyerBackground : 'No professional background has been added yet.') ?></p>
                </section>
              </div>

              <div class="public-lawyer-actions">
                <a class="button button-primary" href="<?= lex_e($ctaLink) ?>">Book appointment</a>
                <a class="button button-secondary" href="<?= lex_e($backLink) ?>"><?= lex_e($backLinkLabel) ?></a>
              </div>

              <?php if ($message !== '' || $error !== ''): ?>
                <div class="public-lawyer-notice <?= $error !== '' ? 'is-error' : 'is-success' ?>" role="status">
                  <?= lex_e($error !== '' ? $error : $message) ?>
                </div>
              <?php endif; ?>
            </div>
          </article>

          <aside class="public-lawyer-panel public-lawyer-side">
            <div class="public-lawyer-portrait">
              <?php if ($avatarUrl !== ''): ?>
                <img src="<?= lex_e($avatarUrl) ?>" alt="Portrait of <?= lex_e($fullName) ?>">
              <?php else: ?>
                <div class="public-lawyer-portrait-fallback" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="8" r="3.15" stroke="currentColor" stroke-width="1.5"></circle>
                    <path d="M7.5 18.25c0-2.75 1.98-4.5 4.5-4.5s4.5 1.75 4.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path>
                  </svg>
                </div>
              <?php endif; ?>
            </div>

            <div class="public-lawyer-info">
              <div class="public-lawyer-info-row">
                <span class="public-lawyer-info-icon">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 6.75h10M7 12h10M7 17.25h6M5.75 4.75h12.5A1.75 1.75 0 0 1 20 6.5v11A1.75 1.75 0 0 1 18.25 19.25H5.75A1.75 1.75 0 0 1 4 17.5v-11A1.75 1.75 0 0 1 5.75 4.75Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </span>
                <span><?= lex_e($specialization !== '' ? $specialization : 'General legal services') ?></span>
              </div>

              <div class="public-lawyer-info-row">
                <span class="public-lawyer-info-icon">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4.75 8.5 12 13.5l7.25-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path><rect x="4" y="6" width="16" height="12" rx="2" stroke="currentColor" stroke-width="1.5"></rect></svg>
                </span>
                <span><?= lex_e($email !== '' ? $email : 'LEXSHIELD lawyer profile') ?></span>
              </div>

              <div class="public-lawyer-info-row">
                <span class="public-lawyer-info-icon">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6.62 3.75h2.3c.58 0 1.08.4 1.21.96l.55 2.38c.11.47-.06.96-.44 1.26l-1.25.98a10.1 10.1 0 0 0 5.68 5.68l.98-1.25c.3-.38.79-.55 1.26-.44l2.38.55c.56.13.96.63.96 1.21v2.3c0 1.03-.83 1.87-1.86 1.87C10.86 19.25 4.75 13.14 4.75 5.61c0-1.03.84-1.86 1.87-1.86Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"></path></svg>
                </span>
                <span><?= lex_e($contactNumber !== '' ? $contactNumber : 'Phone number not provided') ?></span>
              </div>

              <div class="public-lawyer-info-row">
                <span class="public-lawyer-info-icon">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 5.75h10l1.25 2.5v9.5A1.75 1.75 0 0 1 16.5 19.5h-9A1.75 1.75 0 0 1 5.75 17.75v-9.5L7 5.75Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"></path><path d="M8.75 10.5h6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </span>
                <span>Bar roll: <?= lex_e((string) $lawyer['bar_number']) ?></span>
              </div>

              <div class="public-lawyer-info-row">
                <span class="public-lawyer-info-icon">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.5"></circle><path d="M12 8v4l2.6 2.15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></path></svg>
                </span>
                <span>Joined <?= lex_e($joinedLabel) ?></span>
              </div>
            </div>
          </aside>
        </section>

        <section class="public-lawyer-panel public-lawyer-reviews">
          <div class="public-lawyer-reviews-head">
            <div>
              <h2>Client reviews</h2>
              <p>Verified feedback from past clients of this attorney.</p>
            </div>
            <p class="public-lawyer-reviews-count"><span id="reviews-header-count"><?= number_format($totalReviews) ?></span> review<?= $totalReviews === 1 ? '' : 's' ?></p>
          </div>

          <div class="public-review-list<?= count($reviews) > 10 ? ' public-review-list--scrollable' : '' ?>"<?= count($reviews) > 10 ? ' tabindex="0" aria-label="Scrollable client reviews"' : '' ?>>
            <?php foreach ($reviews as $review): ?>
              <?php $reviewerName = $maskReviewerName((string) $review['client_name']); ?>
              <article class="public-lawyer-review-card">
                <div class="public-lawyer-review-head">
                  <div class="public-lawyer-reviewer">
                    <div class="public-lawyer-reviewer-badge"><?= lex_e(strtoupper(substr($reviewerName, 0, 1))) ?></div>
                    <div>
                      <strong><?= lex_e($reviewerName) ?></strong>
                      <span><?= lex_e(date('M j, Y', strtotime((string) $review['created_at']))) ?></span>
                    </div>
                  </div>
                  <span class="public-lawyer-rating-pill"><?= (int) $review['rating'] ?>/5</span>
                </div>

                <div class="public-lawyer-stars" aria-hidden="true">
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="<?= $i <= (int) $review['rating'] ? 'is-filled' : '' ?>"><?= $i <= (int) $review['rating'] ? '&#9733;' : '&#9734;' ?></span>
                  <?php endfor; ?>
                </div>

                <p class="public-lawyer-review-text"><?= lex_e((string) ($review['comment'] ?: 'No written comment was provided for this review.')) ?></p>
              </article>
            <?php endforeach; ?>

            <?php if (!$reviews): ?>
              <article class="public-lawyer-review-card">
                <p class="public-lawyer-review-text">No reviews yet. Be the first to leave feedback after your appointment.</p>
              </article>
            <?php endif; ?>
          </div>

          <div class="public-lawyer-review-actions">
            <?php if ($isClientUser): ?>
              <button type="button" class="button button-secondary" id="open-review-modal">
                <?= $reviewValue > 0 ? 'Update review' : 'Write a review' ?>
              </button>
            <?php else: ?>
              <a class="button button-secondary" href="<?= lex_e($ctaLink) ?>">Book appointment</a>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>
    </section>
  </main>

  <?php if ($lawyer && $isClientUser): ?>
    <div class="public-review-modal" id="public-review-modal" hidden>
      <div class="public-review-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="public-review-title">
        <div class="public-review-modal__head">
          <div>
            <h3 id="public-review-title"><?= $reviewValue > 0 ? 'Update review' : 'Write a review' ?></h3>
            <p class="public-review-modal__hint">Share feedback about professionalism, communication, and overall experience.</p>
          </div>
          <button type="button" class="public-review-modal__close" id="close-review-modal" aria-label="Close review form">✕</button>
        </div>

        <form id="public-review-form" data-endpoint="<?= lex_e(lex_app_url('lawyer/view.php?id=' . $lawyerId . ($returnTo !== '' ? '&return_to=' . rawurlencode($returnTo) : ''))) ?>">
          <input type="hidden" name="action" value="review">
          <input type="hidden" name="lawyer_id" value="<?= (int) $lawyerId ?>">
          <input type="hidden" name="csrf_token" value="<?= lex_e(lex_csrf_token()) ?>">
          <input type="hidden" name="rating" id="review-rating-input" value="<?= (int) $reviewValue ?>">

          <div class="public-review-modal__rating">
            <div class="public-review-modal__stars" id="review-stars">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <button type="button" class="public-review-modal__star <?= $i <= $reviewValue ? 'is-filled' : '' ?>" data-value="<?= $i ?>" aria-label="Rate <?= $i ?> star<?= $i === 1 ? '' : 's' ?>">★</button>
              <?php endfor; ?>
            </div>
            <div class="public-review-modal__stars-note" id="review-rating-note"><?= $reviewValue > 0 ? $reviewValue . '.0 out of 5 selected' : 'Select a rating from 1 to 5.' ?></div>
          </div>

          <label>
            <span class="sr-only">Comment</span>
            <textarea name="comment" id="review-comment" placeholder="Share feedback about the lawyer's professionalism, communication, or results."><?= lex_e($reviewComment) ?></textarea>
          </label>

          <div class="public-review-modal__footer">
            <button type="submit" class="button button-primary" id="review-submit-button"><?= $reviewValue > 0 ? 'Update review' : 'Save review' ?></button>
            <button type="button" class="button button-secondary" id="cancel-review-modal">Cancel</button>
            <div class="public-review-modal__status" id="review-form-status" hidden></div>
          </div>
        </form>
      </div>
    </div>

    <script>
      (() => {
        const modal = document.getElementById('public-review-modal');
        const openButton = document.getElementById('open-review-modal');
        const closeButton = document.getElementById('close-review-modal');
        const cancelButton = document.getElementById('cancel-review-modal');
        const form = document.getElementById('public-review-form');
        const stars = Array.from(document.querySelectorAll('.public-review-modal__star'));
        const ratingInput = document.getElementById('review-rating-input');
        const ratingNote = document.getElementById('review-rating-note');
        const statusNode = document.getElementById('review-form-status');
        const submitButton = document.getElementById('review-submit-button');
        const profileRatingValue = document.getElementById('profile-rating-value');
        const profileReviewCount = document.getElementById('profile-review-count');
        const reviewsHeaderCount = document.getElementById('reviews-header-count');
        const profileRatingStars = document.getElementById('profile-rating-stars');
        let currentRating = Number(ratingInput.value || 0);

        const renderStars = (value) => {
          stars.forEach((star, index) => {
            star.classList.toggle('is-filled', index < value);
          });
          ratingNote.textContent = value ? `${value}.0 out of 5 selected` : 'Select a rating from 1 to 5.';
        };

        const openModal = () => {
          modal.hidden = false;
          document.body.classList.add('has-modal-open');
        };

        const closeModal = () => {
          modal.hidden = true;
          document.body.classList.remove('has-modal-open');
        };

        const setStatus = (message, type) => {
          statusNode.hidden = false;
          statusNode.className = `public-review-modal__status is-${type}`;
          statusNode.textContent = message;
        };

        const renderProfileStars = (value) => {
          if (!profileRatingStars) {
            return;
          }
          const rounded = Math.max(0, Math.min(5, Math.round(value)));
          profileRatingStars.innerHTML = '';
          for (let i = 1; i <= 5; i += 1) {
            const span = document.createElement('span');
            span.className = i <= rounded ? 'is-filled' : '';
            span.innerHTML = i <= rounded ? '&#9733;' : '&#9734;';
            profileRatingStars.appendChild(span);
          }
          profileRatingStars.setAttribute('aria-label', `${Number(value).toFixed(1)} out of 5`);
        };

        renderStars(currentRating);

        openButton?.addEventListener('click', openModal);
        closeButton?.addEventListener('click', closeModal);
        cancelButton?.addEventListener('click', closeModal);

        modal?.addEventListener('click', (event) => {
          if (event.target === modal) {
            closeModal();
          }
        });

        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
          }
        });

        stars.forEach((star) => {
          star.addEventListener('click', () => {
            currentRating = Number(star.dataset.value || 0);
            ratingInput.value = String(currentRating);
            renderStars(currentRating);
            statusNode.hidden = true;
          });
        });

        form?.addEventListener('submit', async (event) => {
          event.preventDefault();
          if (!currentRating) {
            setStatus('Select a rating before saving your review.', 'error');
            return;
          }

          submitButton.disabled = true;
          submitButton.textContent = 'Saving...';
          statusNode.hidden = true;

          try {
            const response = await fetch(form.dataset.endpoint || window.location.href, {
              method: 'POST',
              headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
              },
              body: new URLSearchParams(new FormData(form)).toString()
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
              throw new Error(data.message || 'Unable to save the review right now.');
            }

            if (data.stats) {
              const avgValue = Number(data.stats.rating || 0);
              const avg = avgValue.toFixed(1);
              profileRatingValue.textContent = avg;
              profileReviewCount.textContent = String(Number(data.stats.review_count || 0));
              reviewsHeaderCount.textContent = String(Number(data.stats.review_count || 0));
              renderProfileStars(avgValue);
              currentRating = Number(data.stats.my_rating || currentRating);
              ratingInput.value = String(currentRating);
              renderStars(currentRating);
              submitButton.textContent = currentRating > 0 ? 'Update review' : 'Save review';
              openButton.textContent = currentRating > 0 ? 'Update review' : 'Write a review';
              document.getElementById('review-comment').value = data.stats.my_comment || '';
            }

            setStatus(data.message || 'Review saved.', 'success');
            window.setTimeout(closeModal, 500);
          } catch (err) {
            setStatus(err.message || 'Unable to save the review right now.', 'error');
          } finally {
            submitButton.disabled = false;
            submitButton.textContent = currentRating > 0 ? 'Update review' : 'Save review';
          }
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
