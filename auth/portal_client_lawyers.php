<?php
require_once __DIR__ . '/../config/bootstrap.php';

$user = lex_require_role('client');
$pdo = lex_pdo();
$clientId = lex_user_client_id((int) $user['id']);
$selectedSpecialization = trim(lex_sanitize_text($_GET['specialization'] ?? ''));
$selectedSearch = trim(lex_sanitize_text($_GET['search'] ?? ''));
$message = '';
$error = '';
$isJsonRequest = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || str_contains(strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')), 'xmlhttprequest');

$specializations = lex_recent(
    'SELECT DISTINCT l.specialization
     FROM lawyers l
     JOIN users u ON u.id = l.user_id
     WHERE u.is_active = 1
       AND l.status IN ("active", "busy")
       AND l.specialization <> ""
     ORDER BY l.specialization ASC'
);

$lawyerSql = 'SELECT l.id, l.bar_number, l.specialization, l.status, l.bio, l.background, u.full_name, u.email, u.avatar_stored_name,
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
 WHERE u.is_active = 1
   AND l.status IN ("active", "busy")';
$lawyerParams = ['client_id' => $clientId];
$lawyerSql .= ' ORDER BY u.full_name ASC';
$buildLawyersUrl = static function (string $specialization = '', string $search = ''): string {
    $query = array_filter([
        'specialization' => $specialization,
        'search' => $search,
    ], static fn (string $value): bool => $value !== '');

    return lex_app_url('client/lawyers.php' . ($query ? '?' . http_build_query($query) : ''));
};
$returnTo = $buildLawyersUrl($selectedSpecialization, $selectedSearch);

$normalizeLawyer = static function (array $row) use ($clientId, $returnTo): array {
    $ratingValue = round((float) ($row['avg_rating'] ?? 0), 1);
    $reviewCount = (int) ($row['review_count'] ?? 0);
    $summary = trim((string) ($row['background'] ?: $row['bio']));
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['full_name'],
        'specialization' => (string) ($row['specialization'] ?? ''),
        'status' => (string) ($row['status'] ?? 'inactive'),
        'rating' => $ratingValue,
        'reviewsCount' => $reviewCount,
        'barRoll' => (string) ($row['bar_number'] ?? ''),
        'joinedLabel' => date('M Y'),
        'bio' => $summary !== '' ? $summary : 'No bio provided yet.',
        'avatarUrl' => lex_profile_avatar_url((string) ($row['avatar_stored_name'] ?? '')),
        'appointUrl' => lex_app_url('client/appointment.php?lawyer_id=' . (int) $row['id']),
        'viewProfileUrl' => lex_app_url('lawyer/view.php?id=' . (int) $row['id'] . '&return_to=' . rawurlencode($returnTo)),
        'canAppoint' => strtolower((string) ($row['status'] ?? '')) === 'active',
        'review' => [
            'rating' => (int) ($row['my_rating'] ?? 0),
            'comment' => (string) ($row['my_comment'] ?? ''),
        ],
    ];
};

$lawyers = array_map($normalizeLawyer, lex_recent($lawyerSql, $lawyerParams));

$formatRating = static fn (float|int|string $value): string => number_format((float) $value, 1);
$formatRollNumber = static function (string $value): string {
    $text = trim($value);
    if ($text === '') {
        return 'N/A';
    }
    preg_match_all('/\d+/', $text, $groups);
    $numbers = $groups[0] ?? [];
    return $numbers ? end($numbers) : $text;
};
$initialsFromName = static function (string $name): string {
    $compact = preg_replace('/\s+/', '', $name) ?: 'LW';
    return strtoupper(substr($compact, 0, 2) ?: 'LW');
};
$renderRatingStars = static function (float|int|string $value): string {
    $rating = (float) $value;
    $html = '<div class="rating-stars is-readonly" role="img" aria-label="' . lex_e(number_format($rating, 1) . ' out of 5') . '">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<span class="rating-star' . ($i <= $rating ? ' is-filled' : '') . '" aria-hidden="true">&#9733;</span>';
    }
    return $html . '</div>';
};
$lawyerMatchesSearch = static function (array $lawyer, string $search): bool {
    $needle = strtolower(trim($search));
    if ($needle === '') {
        return true;
    }

    $haystack = strtolower(implode(' ', [
        (string) ($lawyer['name'] ?? ''),
        (string) ($lawyer['specialization'] ?? ''),
        (string) ($lawyer['barRoll'] ?? ''),
        (string) ($lawyer['bio'] ?? ''),
    ]));

    return str_contains($haystack, $needle);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('client/lawyers.php');
        $error = 'Invalid CSRF token.';
    } elseif ((string) ($_POST['action'] ?? '') === 'review') {
        $lawyerId = lex_sanitize_int($_POST['lawyer_id'] ?? 0);
        $rating = lex_sanitize_int($_POST['rating'] ?? 0);
        $comment = lex_sanitize_text($_POST['comment'] ?? '');

        $stmt = $pdo->prepare(
            'SELECT l.id
             FROM lawyers l
             JOIN users u ON u.id = l.user_id
             WHERE l.id = :lawyer_id
               AND u.is_active = 1
               AND l.status IN ("active", "busy")
             LIMIT 1'
        );
        $stmt->execute(['lawyer_id' => $lawyerId]);
        $lawyerExists = (int) ($stmt->fetchColumn() ?: 0);

        if (!$lawyerExists) {
            $error = 'Choose a lawyer first.';
        } elseif ($rating < 1 || $rating > 5) {
            $error = 'Choose a rating from 1 to 5.';
        } else {
            $stmt = $pdo->prepare(
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
                preg_replace('/ ORDER BY u\.full_name ASC$/', ' AND l.id = :lawyer_id ORDER BY u.full_name ASC', $lawyerSql),
                $lawyerParams + ['lawyer_id' => $lawyerId]
            );
            $updatedLawyer = $updatedRows[0] ?? null;
            $payload = [
                'ok' => true,
                'message' => ($rating === 0 ? 'Review saved.' : 'Review saved.'),
                'lawyer' => $updatedLawyer ? $normalizeLawyer($updatedLawyer) : null,
            ];

            if ($isJsonRequest) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $redirect = $returnTo;
            lex_flash_set('success', 'Your review was saved.');
            header('Location: ' . $redirect);
            exit;
        }
    } else {
        $error = 'Unsupported action.';
    }

    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8', true, 422);
        echo json_encode([
            'ok' => false,
            'message' => $error !== '' ? $error : 'Unable to save the review.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$pageProps = [
    'csrfToken' => lex_csrf_token(),
    'reviewEndpoint' => $returnTo,
    'lawyers' => $lawyers,
    'useSampleFallback' => !$lawyers && $selectedSpecialization === '',
    'specialization' => $selectedSpecialization,
    'search' => $selectedSearch,
    'specializations' => array_map(static fn (array $row): string => (string) $row['specialization'], $specializations),
];

lex_page_header('Lawyers', 'lawyers', $user);
?>
<section class="card client-lawyers-card client-lawyers-react-card">
  <h2 class="sr-only">Lawyers</h2>

  <?php if ($message): ?><div class="alert alert-success"><?= lex_e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>

  <script type="application/json" id="lawyers-app-data"><?= json_encode($pageProps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
  <div id="lawyers-app" class="lawyers-react-root" aria-live="polite">
    <div class="lawyer-directory-shell">
      <form class="lawyer-searchbar" method="get">
        <label class="lawyer-searchbar__field">
          <span>Search</span>
          <input type="search" name="search" placeholder="Name, specialization, bar number, or background" value="<?= lex_e($selectedSearch) ?>">
        </label>
        <label class="lawyer-searchbar__field lawyer-searchbar__field--select">
          <span>Specialization</span>
          <select name="specialization" onchange="this.form.submit()">
            <option value="">All specializations</option>
            <?php foreach ($pageProps['specializations'] as $specialization): ?>
              <option value="<?= lex_e($specialization) ?>"<?= $selectedSpecialization === $specialization ? ' selected' : '' ?>><?= lex_e($specialization) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="lawyer-searchbar__actions">
          <button class="button button-primary" type="submit">Search</button>
          <a class="button button-secondary" href="<?= lex_e(lex_app_url('client/lawyers.php')) ?>">Clear</a>
        </div>
      </form>

      <?php
      $fallbackLawyers = array_values(array_filter(
          $lawyers,
          static fn (array $lawyer): bool => ($selectedSpecialization === '' || (string) $lawyer['specialization'] === $selectedSpecialization)
              && $lawyerMatchesSearch($lawyer, $selectedSearch)
      ));
      ?>

      <?php if ($fallbackLawyers): ?>
        <div class="lawyer-directory-grid">
          <?php foreach ($fallbackLawyers as $lawyer): ?>
            <?php
            $status = strtolower((string) ($lawyer['status'] ?? 'inactive'));
            $name = (string) ($lawyer['name'] ?? 'Lawyer');
            $reviewsCount = (int) ($lawyer['reviewsCount'] ?? 0);
            ?>
            <article class="lawyer-card">
              <div class="lawyer-card__media">
                <?php if (!empty($lawyer['avatarUrl'])): ?>
                  <img src="<?= lex_e((string) $lawyer['avatarUrl']) ?>" alt="Avatar for <?= lex_e($name) ?>">
                <?php else: ?>
                  <div class="lawyer-card__placeholder" aria-hidden="true">
                    <span class="lawyer-card__person-icon"></span>
                    <span class="lawyer-card__initials"><?= lex_e($initialsFromName($name)) ?></span>
                  </div>
                <?php endif; ?>
                <span class="status-badge is-<?= lex_e($status) ?>"><?= lex_e($status) ?></span>
              </div>

              <div class="lawyer-card__body">
                <div class="lawyer-card__identity">
                  <h3><?= lex_e($name) ?></h3>
                  <p><span aria-hidden="true">&#8863;</span> <?= lex_e((string) ($lawyer['specialization'] ?: 'General Practice')) ?></p>
                </div>

                <div class="lawyer-card__rating lawyer-card__rating-trigger" aria-label="<?= lex_e($name . ' average rating ' . $formatRating($lawyer['rating'] ?? 0) . ' out of 5') ?>">
                  <?= $renderRatingStars($lawyer['rating'] ?? 0) ?>
                  <span><?= lex_e($formatRating($lawyer['rating'] ?? 0)) ?> - <?= number_format($reviewsCount) ?> review<?= $reviewsCount === 1 ? '' : 's' ?></span>
                </div>

                <div class="lawyer-card__chips">
                  <span>Roll <?= lex_e($formatRollNumber((string) ($lawyer['barRoll'] ?? ''))) ?></span>
                  <span><?= lex_e((string) ($lawyer['joinedLabel'] ?? 'May 2026')) ?></span>
                </div>

                <div class="lawyer-card__actions">
                  <a class="button button-secondary" href="<?= lex_e((string) $lawyer['viewProfileUrl']) ?>">Profile</a>
                  <?php if (!empty($lawyer['canAppoint'])): ?>
                    <a class="button button-primary" href="<?= lex_e((string) $lawyer['appointUrl']) ?>">Book &#8599;</a>
                  <?php else: ?>
                    <button class="button button-primary" type="button" disabled aria-disabled="true" title="<?= $status === 'busy' ? 'This lawyer is currently busy' : 'This lawyer is inactive' ?>">Book &#8599;</button>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state empty-state--lawyers">
          <h3>No lawyers match this filter</h3>
          <p>Try a different specialization or clear the search to see every lawyer in the directory.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/react@18/umd/react.development.js" crossorigin></script>
<script src="https://cdn.jsdelivr.net/npm/react-dom@18/umd/react-dom.development.js" crossorigin></script>
<script src="https://cdn.jsdelivr.net/npm/@babel/standalone/babel.min.js" crossorigin></script>
<script type="text/babel" data-presets="env,react" src="<?= lex_e(lex_asset_url('public/js/lawyers.js')) ?>"></script>
<?php lex_page_footer(); ?>
