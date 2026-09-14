<?php

declare(strict_types=1);

/**
 * Public landing page (index.php). PAO-style homepage with services,
 * about, lawyers, and a quick inquiry form.
 */

$currentUser = lex_current_user();
$currentRole = strtolower(trim((string) (is_array($currentUser) ? ($currentUser['role'] ?? '') : '')));
if ($currentRole === 'attorney') {
    $currentRole = 'lawyer';
}

$dashboardUrl = lex_app_url('go.php');
$loginUrl = $currentUser ? $dashboardUrl : lex_app_url('auth/login.php');
$reserveUrl = $currentUser
    ? ($currentRole === 'client'
        ? (function_exists('lex_nav_href') ? lex_nav_href('client/appointment.php') : lex_app_url('client/appointment.php'))
        : $dashboardUrl)
    : lex_app_url('auth/register.php');
$loginLabel = $currentUser ? 'Open dashboard' : 'Already registered? Login';

$error = '';
$success = '';
$inquiryTopicChoices = function_exists('lex_quick_inquiry_topics')
    ? lex_quick_inquiry_topics()
    : ['Appointment', 'Legal assistance', 'Directory', 'Notary', 'Other', 'Custom'];
$inquiryCustomChoice = function_exists('lex_appointment_custom_choice')
    ? lex_appointment_custom_choice()
    : 'Custom';
$inquiryTopicMaxLen = function_exists('lex_quick_inquiry_topic_max_length')
    ? lex_quick_inquiry_topic_max_length()
    : 190;
$selectedInquiryTopic = '';
$selectedInquiryCustomTopic = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'quick_inquiry') {
    if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
        lex_audit_csrf_failure('home:quick_inquiry');
        $error = 'Invalid CSRF token. Please try again.';
    } else {
        $limit = lex_rate_limit_hit('quick_inquiry', lex_rate_limit_client_ip(), 5, 3600, 3600);
        if (!$limit['allowed']) {
            $error = lex_rate_limit_message((int) $limit['retry_after']);
        } else {
            $firstName = lex_sanitize_text($_POST['first_name'] ?? '');
            $lastName = lex_sanitize_text($_POST['last_name'] ?? '');
            $fullName = trim($firstName . ' ' . $lastName);
            if ($fullName === '') {
                $fullName = lex_sanitize_text($_POST['full_name'] ?? '');
            }
            $email = lex_sanitize_email($_POST['email'] ?? '');
            $phone = lex_sanitize_text($_POST['phone'] ?? '');
            $resolvedTopic = function_exists('lex_resolve_typed_choice')
                ? lex_resolve_typed_choice(
                    lex_sanitize_text($_POST['topic'] ?? ''),
                    lex_sanitize_text($_POST['custom_topic'] ?? ''),
                    $inquiryTopicChoices,
                    $inquiryTopicMaxLen
                )
                : [
                    'choice' => lex_sanitize_text($_POST['topic'] ?? ''),
                    'custom' => '',
                    'value' => lex_sanitize_text($_POST['topic'] ?? ''),
                    'ok' => true,
                ];
            $topicChoice = (string) $resolvedTopic['choice'];
            $topic = (string) $resolvedTopic['value'];
            $selectedInquiryTopic = $topicChoice;
            $selectedInquiryCustomTopic = (string) $resolvedTopic['custom'];
            $message = lex_sanitize_multiline_text($_POST['message'] ?? '');

            if ($fullName === '' || $message === '' || ($email === '' && $phone === '')) {
                $error = 'Please share your name, a way to reach you, and your message.';
            } elseif ($topicChoice === $inquiryCustomChoice && empty($resolvedTopic['ok'])) {
                $error = 'Enter a custom inquiry type.';
            } elseif ($topicChoice !== '' && empty($resolvedTopic['ok'])) {
                $error = 'Choose a valid inquiry topic.';
            } else {
                $pdo = lex_pdo();
                $pdo->prepare(
                    'INSERT INTO quick_inquiries (full_name, email, phone, topic, message) VALUES (:full_name, :email, :phone, :topic, :message)'
                )->execute([
                    'full_name' => $fullName,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'topic' => $topic !== '' ? $topic : null,
                    'message' => $message,
                ]);
                lex_audit('submit_quick_inquiry', 'quick_inquiries', $email !== '' ? $email : $phone);
                if (function_exists('lex_notify_role_users')) {
                    lex_notify_role_users('admin', 'inquiry', 'New quick inquiry from ' . $fullName . '.');
                }
                $success = 'Thanks! Our team will reach out to you shortly.';
                $selectedInquiryTopic = '';
                $selectedInquiryCustomTopic = '';
            }
        }
    }
}

$featuredLawyers = [];
$lawyerQueryOk = false;
try {
    $featuredLawyers = lex_recent(
        "SELECT l.id, u.full_name, u.avatar_stored_name, l.specialization, l.status,
                (SELECT ROUND(AVG(r.rating), 1) FROM lawyer_reviews r WHERE r.lawyer_id = l.id) AS avg_rating,
                (SELECT COUNT(*) FROM lawyer_reviews r WHERE r.lawyer_id = l.id) AS review_count
         FROM lawyers l
         JOIN users u ON u.id = l.user_id
         WHERE l.status = 'active' AND u.is_active = 1
         ORDER BY u.full_name ASC
         LIMIT 24"
    );
    $lawyerQueryOk = true;
} catch (Throwable $e) {
    $featuredLawyers = [];
}

if (!$featuredLawyers && !$lawyerQueryOk) {
    $featuredLawyers = [
        ['full_name' => 'District Public Attorney', 'specialization' => 'Criminal Defense', 'avg_rating' => 4.8, 'review_count' => 18, 'avatar_stored_name' => ''],
        ['full_name' => 'Assistant Public Attorney', 'specialization' => 'Civil and Family Cases', 'avg_rating' => 4.6, 'review_count' => 12, 'avatar_stored_name' => ''],
        ['full_name' => 'Public Attorney', 'specialization' => 'Labor and Administrative', 'avg_rating' => 4.5, 'review_count' => 9, 'avatar_stored_name' => ''],
        ['full_name' => 'Public Attorney', 'specialization' => 'Legal Counseling', 'avg_rating' => 4.7, 'review_count' => 15, 'avatar_stored_name' => ''],
    ];
}

$heroUrl = lex_pao_hero_url();
$lawyerCount = max(1, count($featuredLawyers));
$ratingTotal = 0.0;
$ratedCount = 0;
$reviewTotal = 0;
foreach ($featuredLawyers as $lawyer) {
    $reviewTotal += (int) ($lawyer['review_count'] ?? 0);
    if ($lawyer['avg_rating'] !== null && $lawyer['avg_rating'] !== '') {
        $ratingTotal += (float) $lawyer['avg_rating'];
        $ratedCount++;
    }
}
$avgRating = $ratedCount > 0 ? round($ratingTotal / $ratedCount, 1) : 4.7;
$directoryBar = (int) min(100, max(16, $lawyerCount * 25));
$trustBar = (int) min(100, max(16, round(($avgRating / 5) * 100)));
$reviewBar = (int) min(100, max(16, $reviewTotal > 0 ? min(100, 24 + $reviewTotal) : 16));
$profileLabel = $lawyerCount === 1 ? '1 profile' : $lawyerCount . ' profiles';
$reviewLabel = $reviewTotal === 1 ? '1 rating' : $reviewTotal . ' ratings';
$directorySpecs = [];
foreach ($featuredLawyers as $lawyer) {
    $spec = trim((string) ($lawyer['specialization'] ?? ''));
    if ($spec !== '') {
        $directorySpecs[$spec] = $spec;
    }
}
ksort($directorySpecs, SORT_NATURAL | SORT_FLAG_CASE);

lex_pao_page_header('Equal Access to Justice for All', 'home', 'pao-home');
?>
  <?php foreach (lex_flash_consume() as $flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?> pao-flash"><?= lex_e($flash['message']) ?></div>
  <?php endforeach; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error pao-flash"><?= lex_e($error) ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="alert alert-success pao-flash"><?= lex_e($success) ?></div><?php endif; ?>

  <section class="pao-hero<?= $heroUrl !== '' ? ' pao-hero--photo' : '' ?>" id="home"<?= $heroUrl !== '' ? ' aria-label="Iloilo City Hall, Plaza Libertad"' : '' ?>>
    <div class="pao-hero-copy">
      <p class="pao-kicker">PAO Iloilo</p>
      <h1>Book a free legal appointment</h1>
      <p>Walk in Monday–Friday, 8:00 AM–5:00 PM, or reserve a schedule online.</p>
      <div class="pao-hero-actions">
        <a class="pao-btn pao-btn-blue" href="<?= lex_e($reserveUrl) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3V2Zm13 8H4v10h16V10Z" fill="currentColor"/></svg>
          Make a Reservation
        </a>
        <a class="pao-btn pao-btn-ghost" href="#resources">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 15h-2v-6h2Zm0-8h-2V7h2Z" fill="currentColor"/></svg>
          Who qualifies
        </a>
      </div>
      <div class="pao-hero-metrics">
        <article>
          <span>Walk-in</span>
          <strong>Mon–Fri</strong>
        </article>
        <article>
          <span>Hours</span>
          <strong>8:00–5:00</strong>
        </article>
        <article>
          <span>Office</span>
          <strong>Iloilo</strong>
        </article>
      </div>
    </div>
    <aside class="pao-hero-panel" aria-label="PAO Iloilo directory snapshot">
      <div class="pao-hero-panel-copy">
        <strong>PAO Iloilo</strong>
        <span>Legal compliance</span>
      </div>
      <div class="pao-hero-bars">
        <div>
          <span>Directory coverage</span>
          <b><?= lex_e($profileLabel) ?></b>
          <i style="--pao-bar: <?= (int) $directoryBar ?>%"></i>
        </div>
        <div>
          <span>Client trust signal</span>
          <b><?= lex_e(number_format($avgRating, 1)) ?>/5</b>
          <i style="--pao-bar: <?= (int) $trustBar ?>%"></i>
        </div>
        <div>
          <span>Review participation</span>
          <b><?= lex_e($reviewLabel) ?></b>
          <i style="--pao-bar: <?= (int) $reviewBar ?>%"></i>
        </div>
      </div>
    </aside>
  </section>

  <section class="pao-band pao-reserve-band" id="reservation">
    <div class="pao-reserve-copy">
      <p>PAO Iloilo walk-in hours: Monday to Friday, 8:00 AM to 5:00 PM · Hall of Justice, Bonifacio Drive</p>
    </div>
    <div class="pao-reserve-actions">
      <a class="pao-btn pao-btn-ghost" href="<?= lex_e($loginUrl) ?>"><?= lex_e($loginLabel) ?></a>
    </div>
  </section>

  <section class="pao-band pao-about-band" id="attorneys">
    <div class="pao-section-head">
      <div>
        <p class="pao-eyebrow">Lawyer directory</p>
        <h2>Find the right legal professional faster.</h2>
      </div>
    </div>
    <form class="pao-directory-search" method="get" action="#attorneys" role="search">
      <label class="sr-only" for="directory-search-q">Search lawyers</label>
      <input id="directory-search-q" type="search" name="q" placeholder="Search by name, specialization, or background">
      <label class="sr-only" for="directory-search-spec">Specialization</label>
      <select id="directory-search-spec" name="spec">
        <option value="">Specialization</option>
        <?php foreach ($directorySpecs as $specOption): ?>
          <option value="<?= lex_e($specOption) ?>"><?= lex_e($specOption) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="pao-btn pao-btn-blue" type="submit">Search</button>
      <button class="pao-btn pao-btn-ghost" type="reset">Clear</button>
    </form>
    <div class="pao-lawyer-row pao-lawyer-row--directory">
      <?php foreach ($featuredLawyers as $lawyer): ?>
        <?php
          $avatarUrl = lex_profile_avatar_url((string) ($lawyer['avatar_stored_name'] ?? ''));
          $initials = strtoupper(substr(preg_replace('/\s+/', '', (string) $lawyer['full_name']) ?: 'PA', 0, 2));
          $specLabel = (string) ($lawyer['specialization'] ?: 'General Practice');
          $rating = $lawyer['avg_rating'] !== null && $lawyer['avg_rating'] !== '' ? (float) $lawyer['avg_rating'] : 0.0;
          $reviews = (int) ($lawyer['review_count'] ?? 0);
          $lawyerId = (int) ($lawyer['id'] ?? 0);
          $profileUrl = $lawyerId > 0
              ? lex_app_url('lawyer/view.php?id=' . $lawyerId . '&return_to=' . rawurlencode(lex_app_url('index.php') . '#attorneys'))
              : '#attorneys';
          $bookUrl = ($lawyerId > 0 && $currentRole === 'client')
              ? lex_app_url('client/appointment.php?lawyer_id=' . $lawyerId)
              : $reserveUrl;
        ?>
        <article class="pao-lawyer-card pao-lawyer-card--directory" data-name="<?= lex_e((string) $lawyer['full_name']) ?>" data-spec="<?= lex_e($specLabel) ?>">
          <div class="pao-lawyer-avatar">
            <?php if ($avatarUrl !== ''): ?><img src="<?= lex_e($avatarUrl) ?>" alt=""><?php else: ?><?= lex_e($initials) ?><?php endif; ?>
          </div>
          <div class="pao-lawyer-copy">
            <h3><?= lex_e((string) $lawyer['full_name']) ?></h3>
            <p><?= lex_e($specLabel) ?></p>
            <?= lex_pao_star_rating($rating, $reviews) ?>
            <div class="pao-lawyer-actions">
              <a class="pao-btn pao-btn-ghost" href="<?= lex_e($profileUrl) ?>">View</a>
              <a class="pao-btn pao-btn-blue" href="<?= lex_e($bookUrl) ?>">Book</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$featuredLawyers): ?>
        <p class="pao-empty">Public attorney profiles for the Iloilo district will appear here once added.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="pao-band pao-services-band" id="services">
    <div class="pao-services-layout">
      <div class="pao-services-main">
        <p class="pao-eyebrow">What PAO delivers</p>
        <h2>Our Services</h2>
        <p>Free legal aid and appointments for qualified clients in Iloilo.</p>
        <div class="pao-service-grid">
          <article>
            <span class="pao-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3V2Zm13 8H4v10h16V10Z" fill="currentColor"/></svg></span>
            <h3>Book an Appointment</h3>
            <p>Schedule your legal consultation with ease.</p>
          </article>
          <article>
            <span class="pao-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Zm0 2.5L18.5 9H14ZM8 13h8v2H8Zm0 4h5v2H8Z" fill="currentColor"/></svg></span>
            <h3>Legal Assistance</h3>
            <p>Get free legal services from PAO.</p>
          </article>
          <article>
            <span class="pao-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4ZM8 12a3.5 3.5 0 1 0-3.5-3.5A3.5 3.5 0 0 0 8 12Zm8 2c-3 0-8 1.5-8 4.5V21h16v-2.5c0-3-5-4.5-8-4.5Z" fill="currentColor"/></svg></span>
            <h3>Qualified Lawyers</h3>
            <p>Professional and dedicated public attorneys.</p>
          </article>
          <article>
            <span class="pao-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5Z" fill="currentColor"/></svg></span>
            <h3>Secure &amp; Confidential</h3>
            <p>Your information is safe with us.</p>
          </article>
        </div>
      </div>
    </div>
  </section>

  <section class="pao-band pao-about-band" id="about">
    <div class="pao-about-intro">
      <h2>About PAO</h2>
      <p>The Public Attorney's Office Iloilo District provides free legal services to indigent persons in criminal, civil, labor, and administrative cases, in keeping with equal access to justice.</p>
      <p>Walk-in assistance is available at the Hall of Justice on Bonifacio Drive and through coordinated hours at Iloilo City Hall, Plaza Libertad.</p>
    </div>
  </section>

  <section class="pao-band pao-resources-band" id="resources">
    <div class="pao-about-intro">
      <p class="pao-eyebrow">How to get help</p>
      <h2>Legal Resources</h2>
    </div>
    <ol class="pao-steps">
      <li>
        <h3>1. Check if you qualify</h3>
        <p>Free legal aid is for indigent clients in criminal, civil, labor, and administrative cases in Iloilo.</p>
      </li>
      <li>
        <h3>2. Visit or register</h3>
        <p>Walk in during office hours or create a client account to request help online.</p>
      </li>
      <li>
        <h3>3. Reserve a schedule</h3>
        <p>Ask for an appointment with a public attorney and bring your indigency papers.</p>
      </li>
    </ol>
    <div class="pao-block">
      <h2>Who can apply</h2>
      <ul class="pao-list">
        <li>Valid government ID</li>
        <li>Barangay certificate of indigency</li>
        <li>Proof of income, or a note if you have none</li>
      </ul>
    </div>
    <div class="pao-office-row">
      <article>
        <h3>Hall of Justice</h3>
        <p>Bonifacio Drive, Iloilo City</p>
        <p>Monday – Friday, 8:00 AM – 5:00 PM</p>
        <p>(033) 333-2618</p>
      </article>
      <article>
        <h3>Iloilo City Hall</h3>
        <p>Plaza Libertad, Iloilo City, Philippines 5000</p>
        <p>Coordinated hours at the City Hall desk</p>
        <p>(033) 508-6989</p>
      </article>
    </div>
    <div class="pao-faq">
      <h2>FAQ</h2>
      <details>
        <summary>Walk-in or online?</summary>
        <p>Both. Walk in Monday to Friday, 8:00 AM to 5:00 PM, or reserve a schedule through a client account.</p>
      </details>
      <details>
        <summary>Which window should I visit?</summary>
        <p>Iloilo District cases are received at the Hall of Justice on Bonifacio Drive. The Iloilo City Hall desk at Plaza Libertad can point you to the right district window.</p>
      </details>
      <details>
        <summary>What cases does PAO handle?</summary>
        <p>Criminal, civil, labor, and administrative cases for qualified indigent clients. PAO does not replace private counsel for those who can afford a lawyer.</p>
      </details>
    </div>
  </section>

  <section class="pao-band pao-inquiry-band" id="contact">
    <div class="pao-inquiry-copy">
      <p class="pao-eyebrow">Contact</p>
      <h2>Need help getting started?</h2>
      <p>Use the portal for appointments, or send a short note to the Iloilo office. Include a name and either an email or phone number.</p>
      <div class="pao-contact-points">
        <article>
          <span class="pao-support-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5L4 8V6l8 5 8-5Z" fill="currentColor"/></svg></span>
          <div>
            <strong>Email support</strong>
            <span>General inquiries and platform coordination.</span>
          </div>
        </article>
        <article>
          <span class="pao-support-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5Z" fill="currentColor"/></svg></span>
          <div>
            <strong>Portal access</strong>
            <span>Create a client account to reserve a schedule.</span>
          </div>
        </article>
        <article>
          <span class="pao-support-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3V2Zm13 8H4v10h16V10Z" fill="currentColor"/></svg></span>
          <div>
            <strong>Appointments</strong>
            <span>Monday – Friday, 8:00 AM – 5:00 PM</span>
          </div>
        </article>
      </div>
    </div>
    <form method="post" class="pao-inquiry-form">
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="quick_inquiry">
      <p class="pao-eyebrow">Quick inquiry</p>
      <h2>Send a short note</h2>
      <p>Include your name and either an email or phone number. The Iloilo office will review it.</p>
      <label>First name <input type="text" name="first_name" required placeholder="First name"></label>
      <label>Last name <input type="text" name="last_name" required placeholder="Last name"></label>
      <label>Email address <input type="email" name="email" placeholder="Email address"></label>
      <label>Phone number <input type="text" name="phone" placeholder="Phone number"></label>
      <label class="pao-full">Choose a topic
        <select name="topic" data-type-choice>
          <option value="">Choose a topic</option>
          <?php foreach ($inquiryTopicChoices as $topicOption): ?>
            <option value="<?= lex_e($topicOption) ?>"<?= $selectedInquiryTopic === $topicOption ? ' selected' : '' ?>><?= lex_e($topicOption) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="pao-full" data-custom-type-wrap<?= $selectedInquiryTopic === $inquiryCustomChoice ? '' : ' hidden' ?>>
        Custom type
        <input type="text" name="custom_topic" value="<?= lex_e($selectedInquiryCustomTopic) ?>" maxlength="<?= (int) $inquiryTopicMaxLen ?>" placeholder="Describe the inquiry type" data-custom-type<?= $selectedInquiryTopic === $inquiryCustomChoice ? ' required' : '' ?>>
      </label>
      <label class="pao-full">Message <textarea name="message" rows="4" required placeholder="Tell us what you need help with."></textarea></label>
      <button class="pao-btn pao-btn-blue pao-full" type="submit">Send inquiry</button>
    </form>
  </section>
<?php
lex_pao_page_footer(true);
