<?php

declare(strict_types=1);

/**
 * Shared implementation behind admin/messages.php, lawyer/messages.php and
 * client/messages.php. All three just call lex_messages_render_page() with
 * the role they expect - this file contains the actual query/render logic
 * so the three role variants can never drift out of sync.
 *
 * Every query here is a bound, prepared statement (see security notes in
 * config/bootstrap.php); the only "dynamic" SQL fragment is the outgoing
 * recipient role, which is always drawn from a hard-coded whitelist, never
 * from request input.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/lock.php';
require_once __DIR__ . '/calls.php';

if (!function_exists('lex_messages_normalize_recipient')) {
    /**
     * @param array<string, mixed> $row
     * @return array{id:int, full_name:string, role:string, avatar_stored_name:string, case_id:int}
     */
    function lex_messages_normalize_recipient(array $row): array
    {
        $role = strtolower(trim((string) ($row['role'] ?? 'client')));
        if ($role === 'attorney') {
            $role = 'lawyer';
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'full_name' => (string) ($row['full_name'] ?? 'User'),
            'role' => $role,
            'avatar_stored_name' => (string) ($row['avatar_stored_name'] ?? ''),
            'case_id' => (int) ($row['case_id'] ?? 0),
        ];
    }
}

if (!function_exists('lex_messages_merge_recipients')) {
    /**
     * @param array<int, array<string, mixed>> ...$groups
     * @return array<int, array{id:int, full_name:string, role:string, avatar_stored_name:string, case_id:int}>
     */
    function lex_messages_merge_recipients(array ...$groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $item = lex_messages_normalize_recipient($row);
                if ($item['id'] <= 0) {
                    continue;
                }
                if (isset($out[$item['id']]) && $item['case_id'] <= 0 && $out[$item['id']]['case_id'] > 0) {
                    continue;
                }
                $out[$item['id']] = $item;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('lex_messages_active_admins')) {
    /**
     * @return array<int, array{id:int, full_name:string, role:string, avatar_stored_name:string, case_id:int}>
     */
    function lex_messages_active_admins(int $exceptUserId = 0): array
    {
        $rows = lex_recent(
            'SELECT u.id, u.full_name, u.role, u.avatar_stored_name, 0 AS case_id
             FROM users u
             WHERE u.is_active = 1
               AND LOWER(TRIM(u.role)) = \'admin\'
             ORDER BY u.full_name ASC'
        );
        $out = [];
        foreach ($rows as $row) {
            $item = lex_messages_normalize_recipient($row);
            if ($item['id'] <= 0 || ($exceptUserId > 0 && $item['id'] === $exceptUserId)) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }
}

if (!function_exists('lex_messages_allowed_recipients')) {
    /**
     * Who the current user is allowed to start a new conversation with.
     *
     * @return array<int, array{id:int, full_name:string, role:string, avatar_stored_name:string, case_id:int}>
     */
    function lex_messages_allowed_recipients(array $user): array
    {
        $role = strtolower(trim((string) ($user['role'] ?? '')));
        if ($role === 'attorney') {
            $role = 'lawyer';
        }
        $userId = (int) ($user['id'] ?? 0);

        if ($role === 'admin') {
            $rows = lex_recent(
                'SELECT u.id, u.full_name, u.role, u.avatar_stored_name, 0 AS case_id
                 FROM users u
                 WHERE u.is_active = 1
                   AND u.id <> :me
                   AND LOWER(TRIM(u.role)) IN (\'lawyer\', \'attorney\')
                 ORDER BY u.full_name ASC',
                ['me' => $userId]
            );
            return lex_messages_merge_recipients($rows);
        }

        if ($role === 'lawyer') {
            $lawyerId = lex_user_lawyer_id($userId);
            $rows = $lawyerId > 0 ? lex_recent(
                'SELECT DISTINCT u.id, u.full_name, u.role, u.avatar_stored_name, c.id AS case_id
                 FROM cases c
                 JOIN clients cl ON cl.id = c.client_id
                 JOIN users u ON u.id = cl.user_id
                 WHERE c.lawyer_id = :lawyer_id AND u.is_active = 1
                 ORDER BY u.full_name ASC',
                ['lawyer_id' => $lawyerId]
            ) : [];
            return lex_messages_merge_recipients(lex_messages_active_admins($userId), $rows);
        }

        if ($role === 'client') {
            $clientId = lex_user_client_id($userId);
            $rows = $clientId > 0 ? lex_recent(
                'SELECT DISTINCT u.id, u.full_name, u.role, u.avatar_stored_name, c.id AS case_id
                 FROM cases c
                 JOIN lawyers l ON l.id = c.lawyer_id
                 JOIN users u ON u.id = l.user_id
                 WHERE c.client_id = :client_id AND u.is_active = 1
                 ORDER BY u.full_name ASC',
                ['client_id' => $clientId]
            ) : [];
            return lex_messages_merge_recipients($rows);
        }

        return [];
    }
}

if (!function_exists('lex_messages_conversations')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function lex_messages_conversations(int $userId): array
    {
        lex_messages_table_ensure();
        $stmt = lex_pdo()->prepare(
            'SELECT
                other.id AS other_id,
                other.full_name AS other_name,
                other.role AS other_role,
                other.avatar_stored_name AS other_avatar,
                last_m.body AS last_body,
                last_m.body_encryption_algorithm AS last_body_encryption_algorithm,
                last_m.body_encryption_iv AS last_body_encryption_iv,
                last_m.body_encryption_tag AS last_body_encryption_tag,
                last_m.created_at AS last_created_at,
                (SELECT COALESCE(MAX(imp.is_important), 0)
                   FROM messages imp
                  WHERE ((imp.sender_id = :viewer9 AND imp.receiver_id = other.id)
                      OR (imp.sender_id = other.id AND imp.receiver_id = :viewer10))
                    AND NOT EXISTS (SELECT 1 FROM message_deletions mdi WHERE mdi.message_id = imp.id AND mdi.user_id = :viewer11)
                ) AS last_important,
                last_m.sender_id AS last_sender_id,
                (SELECT COUNT(*) FROM messages m2
                    WHERE m2.receiver_id = :viewer1 AND m2.sender_id = other.id AND m2.is_read = 0
                      AND NOT EXISTS (SELECT 1 FROM message_deletions md2 WHERE md2.message_id = m2.id AND md2.user_id = :viewer2)
                ) AS unread_count
             FROM (
                SELECT DISTINCT CASE WHEN sender_id = :viewer3 THEN receiver_id ELSE sender_id END AS other_user_id
                FROM messages
                WHERE sender_id = :viewer4 OR receiver_id = :viewer5
             ) AS partners
             JOIN users other ON other.id = partners.other_user_id
             JOIN messages last_m ON last_m.id = (
                SELECT m3.id FROM messages m3
                WHERE ((m3.sender_id = :viewer6 AND m3.receiver_id = other.id) OR (m3.sender_id = other.id AND m3.receiver_id = :viewer7))
                  AND NOT EXISTS (SELECT 1 FROM message_deletions md3 WHERE md3.message_id = m3.id AND md3.user_id = :viewer8)
                ORDER BY m3.id DESC LIMIT 1
             )
             ORDER BY last_m.created_at DESC'
        );
        $stmt->execute([
            'viewer1' => $userId, 'viewer2' => $userId, 'viewer3' => $userId,
            'viewer4' => $userId, 'viewer5' => $userId, 'viewer6' => $userId,
            'viewer7' => $userId, 'viewer8' => $userId,
            'viewer9' => $userId, 'viewer10' => $userId, 'viewer11' => $userId,
        ]);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['last_body'] = lex_messages_decrypt_body(
                isset($row['last_body']) ? (string) $row['last_body'] : null,
                [
                    'body_encryption_algorithm' => $row['last_body_encryption_algorithm'] ?? '',
                    'body_encryption_iv' => $row['last_body_encryption_iv'] ?? '',
                    'body_encryption_tag' => $row['last_body_encryption_tag'] ?? '',
                ]
            );
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('lex_messages_thread')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function lex_messages_thread(int $viewerId, int $otherId, int $limit = 200): array
    {
        lex_messages_table_ensure();
        $stmt = lex_pdo()->prepare(
            'SELECT m.*, su.full_name AS sender_name, su.role AS sender_role
             FROM messages m
             JOIN users su ON su.id = m.sender_id
             WHERE ((m.sender_id = :viewer1 AND m.receiver_id = :other1) OR (m.sender_id = :other2 AND m.receiver_id = :viewer2))
               AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :viewer3)
             ORDER BY m.id ASC
             LIMIT :limit'
        );
        $stmt->bindValue('viewer1', $viewerId, PDO::PARAM_INT);
        $stmt->bindValue('other1', $otherId, PDO::PARAM_INT);
        $stmt->bindValue('other2', $otherId, PDO::PARAM_INT);
        $stmt->bindValue('viewer2', $viewerId, PDO::PARAM_INT);
        $stmt->bindValue('viewer3', $viewerId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['body'] = lex_messages_decrypt_body(
                isset($row['body']) ? (string) $row['body'] : null,
                $row
            );
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('lex_messages_roles_may_chat')) {
    /**
     * Clients talk with their attorney only. Admins talk with attorneys only.
     * Client ↔ admin is never allowed.
     */
    function lex_messages_roles_may_chat(string $fromRole, string $toRole): bool
    {
        $norm = static function (string $role): string {
            $role = strtolower(trim($role));
            return $role === 'attorney' ? 'lawyer' : $role;
        };
        $from = $norm($fromRole);
        $to = $norm($toRole);
        $pair = [$from, $to];
        sort($pair);

        return $pair === ['admin', 'lawyer'] || $pair === ['client', 'lawyer'];
    }
}

if (!function_exists('lex_messages_can_contact')) {
    function lex_messages_can_contact(array $allowed, int $recipientId): ?array
    {
        if ($recipientId <= 0) {
            return null;
        }
        foreach ($allowed as $row) {
            if ((int) ($row['id'] ?? 0) === $recipientId) {
                return lex_messages_normalize_recipient($row);
            }
        }
        return null;
    }
}

if (!function_exists('lex_messages_hide_conversation')) {
    /**
     * Hide every message in a thread for one viewer (Messenger "delete chat").
     * The other person still has their copy.
     */
    function lex_messages_hide_conversation(int $viewerId, int $otherId): void
    {
        if ($viewerId <= 0 || $otherId <= 0) {
            return;
        }
        lex_message_deletions_table_ensure();
        $stmt = lex_pdo()->prepare(
            'INSERT IGNORE INTO message_deletions (message_id, user_id)
             SELECT id, :viewer FROM messages
             WHERE (sender_id = :viewer2 AND receiver_id = :other)
                OR (sender_id = :other2 AND receiver_id = :viewer3)'
        );
        $stmt->execute([
            'viewer' => $viewerId,
            'viewer2' => $viewerId,
            'other' => $otherId,
            'other2' => $otherId,
            'viewer3' => $viewerId,
        ]);
    }
}

if (!function_exists('lex_messages_seed_conversations')) {
    /**
     * Inbox lists only chats with remaining (not deleted-for-you) messages.
     * Assigned contacts stay in New message; they are not re-inserted after delete.
     *
     * @param array<int, array<string, mixed>> $conversations
     * @param array<int, array<string, mixed>> $allowed
     * @return array<int, array<string, mixed>>
     */
    function lex_messages_seed_conversations(array $conversations, array $allowed, int $viewerId): array
    {
        unset($allowed, $viewerId);

        return $conversations;
    }
}

if (!function_exists('lex_messages_render_conversation_item')) {
    function lex_messages_render_conversation_item(array $conversation, int $activeOtherId, string $role): void
    {
        $otherId = (int) $conversation['other_id'];
        $unread = (int) ($conversation['unread_count'] ?? 0);
        $important = (int) ($conversation['last_important'] ?? 0);
        $avatarUrl = lex_profile_avatar_url((string) ($conversation['other_avatar'] ?? ''));
        $name = (string) ($conversation['other_name'] ?? 'User');
        $initials = strtoupper(substr(preg_replace('/\s+/', '', $name) ?: 'U', 0, 2));
        $preview = trim((string) ($conversation['last_body'] ?? ''));
        $when = trim((string) ($conversation['last_created_at'] ?? ''));
        if ($preview === '') {
            $preview = $when === '' ? 'Start a conversation' : 'Attachment';
        }
        $timeLabel = $when !== '' ? lex_message_timestamp($when) : '';
        $otherRole = ucfirst((string) ($conversation['other_role'] ?? ''));
        if (function_exists('mb_strimwidth')) {
            $preview = mb_strimwidth($preview, 0, 52, '…');
        } elseif (strlen($preview) > 52) {
            $preview = substr($preview, 0, 51) . '…';
        }
        ?>
        <div class="conversation-item inbox-thread<?= $otherId === $activeOtherId ? ' is-active' : '' ?>" data-conversation-item data-unread="<?= $unread > 0 ? '1' : '0' ?>" data-important="<?= $important ? '1' : '0' ?>">
          <a class="conversation-item-link" href="<?= lex_e(function_exists('lex_nav_href') ? lex_nav_href('chat.php?with=' . $otherId) : lex_app_url('chat.php?with=' . $otherId)) ?>">
            <?php if ($avatarUrl !== ''): ?>
              <img class="conversation-avatar" src="<?= lex_e($avatarUrl) ?>" alt="">
            <?php else: ?>
              <span class="conversation-avatar" aria-hidden="true"><?= lex_e($initials) ?></span>
            <?php endif; ?>
            <span class="inbox-thread-copy">
              <span class="conversation-item-top">
                <span class="conversation-item-title"><strong><?= lex_e($name) ?></strong></span>
                <?php if ($timeLabel !== ''): ?><small><?= lex_e($timeLabel) ?></small><?php endif; ?>
              </span>
              <span class="conversation-item-bottom">
                <small class="muted"><?= lex_e($preview) ?></small>
                <?php if ($unread > 0): ?><span class="badge"><?= (int) $unread ?></span><?php endif; ?>
              </span>
              <span class="inbox-thread-role"><?= lex_e($otherRole) ?></span>
            </span>
          </a>
          <form method="post" class="conversation-delete-form inbox-delete-form" data-no-loading onclick="event.stopPropagation();" onsubmit="return confirm('Delete this chat? It will be removed from your inbox. The other person will still have the messages.');">
            <?= lex_csrf_field() ?>
            <input type="hidden" name="action" value="delete_conversation">
            <input type="hidden" name="with" value="<?= (int) $otherId ?>">
            <button class="inbox-delete-btn" type="submit" title="Delete chat" aria-label="Delete chat" style="display:inline-flex;width:44px;min-width:44px;height:44px;min-height:44px;align-items:center;justify-content:center;cursor:pointer;pointer-events:auto;position:relative;z-index:6;touch-action:manipulation;box-sizing:border-box;" onclick="event.stopPropagation();">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v9h-2V9Zm4 0h2v9h-2V9ZM8 9h2v9H8V9Z" fill="currentColor"/></svg>
              <span class="inbox-delete-label">Delete</span>
            </button>
          </form>
        </div>
        <?php
    }
}

if (!function_exists('lex_messages_render_page')) {
    function lex_messages_render_page(string $expectedRole): void
    {
        $need = strtolower(trim($expectedRole)) === 'lawyer' ? ['lawyer', 'attorney'] : [$expectedRole];
        $user = lex_require_role($need);
        lex_messages_table_ensure();
        lex_message_deletions_table_ensure();

        $userId = (int) $user['id'];
        $error = '';
        $lockInbox = lex_messages_lock_applies($user) && lex_messages_lock_handle_post($user, $error);
        $showChangePin = lex_messages_lock_applies($user) && !$lockInbox && (string) ($_GET['change'] ?? '') === '1';

        if ($lockInbox || $showChangePin) {
            lex_messages_lock_render($user, $error);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!lex_csrf_validate($_POST['csrf_token'] ?? null)) {
                lex_audit_csrf_failure($expectedRole . '/messages.php');
                $error = 'Invalid CSRF token. Please try again.';
            } else {
                $action = (string) ($_POST['action'] ?? 'send');

                if ($action === 'delete_conversation') {
                    $otherId = lex_sanitize_int($_POST['with'] ?? 0);
                    if ($otherId > 0) {
                        lex_messages_hide_conversation($userId, $otherId);
                        lex_audit('delete_conversation', 'messages', (string) $otherId);
                        lex_flash_set('success', 'Chat removed from your inbox.');
                    }
                    if (function_exists('lex_redirect_app_file')) {
                        lex_redirect_app_file('chat.php');
                    }
                    header('Location: ' . (function_exists('lex_nav_href') ? lex_nav_href('chat.php') : lex_app_url('chat.php')));
                    exit;
                }

                if ($action === 'toggle_important') {
                    $otherId = lex_sanitize_int($_POST['with'] ?? 0);
                    $allowed = lex_messages_allowed_recipients($user);
                    if ($otherId > 0 && lex_messages_can_contact($allowed, $otherId)) {
                        $cur = lex_pdo()->prepare(
                            'SELECT COALESCE(MAX(is_important), 0) FROM messages
                             WHERE ((sender_id = :me1 AND receiver_id = :other1) OR (sender_id = :other2 AND receiver_id = :me2))
                               AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = messages.id AND md.user_id = :me3)'
                        );
                        $cur->execute([
                            'me1' => $userId,
                            'other1' => $otherId,
                            'other2' => $otherId,
                            'me2' => $userId,
                            'me3' => $userId,
                        ]);
                        $mark = ((int) $cur->fetchColumn() > 0) ? 0 : 1;
                        if ($mark === 1) {
                            $flag = lex_pdo()->prepare(
                                'UPDATE messages SET is_important = 1
                                 WHERE id = (
                                    SELECT id FROM (
                                      SELECT m.id FROM messages m
                                      WHERE ((m.sender_id = :me1 AND m.receiver_id = :other1) OR (m.sender_id = :other2 AND m.receiver_id = :me2))
                                        AND NOT EXISTS (SELECT 1 FROM message_deletions md WHERE md.message_id = m.id AND md.user_id = :me3)
                                      ORDER BY m.id DESC LIMIT 1
                                    ) AS latest_visible
                                 )'
                            );
                            $flag->execute([
                                'me1' => $userId,
                                'other1' => $otherId,
                                'other2' => $otherId,
                                'me2' => $userId,
                                'me3' => $userId,
                            ]);
                        } else {
                            $clear = lex_pdo()->prepare(
                                'UPDATE messages SET is_important = 0
                                 WHERE (sender_id = :me1 AND receiver_id = :other1) OR (sender_id = :other2 AND receiver_id = :me2)'
                            );
                            $clear->execute([
                                'me1' => $userId,
                                'other1' => $otherId,
                                'other2' => $otherId,
                                'me2' => $userId,
                            ]);
                        }
                        lex_audit('toggle_conversation_important', 'messages', (string) $otherId);
                    }
                    $target = $otherId > 0 ? 'chat.php?with=' . $otherId : 'chat.php';
                    if (function_exists('lex_redirect_app_file')) {
                        lex_redirect_app_file($target);
                    }
                    header('Location: ' . (function_exists('lex_nav_href') ? lex_nav_href($target) : lex_app_url($target)));
                    exit;
                }

                if ($action === 'delete_message') {
                    $messageId = lex_sanitize_int($_POST['message_id'] ?? 0);
                    $unsend = (string) ($_POST['unsend'] ?? '') === '1';
                    $returnTo = lex_sanitize_int($_POST['with'] ?? 0);
                    $row = null;
                    if ($messageId > 0) {
                        $stmt = lex_pdo()->prepare('SELECT id, sender_id, receiver_id FROM messages WHERE id = :id LIMIT 1');
                        $stmt->execute(['id' => $messageId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && ((int) $row['sender_id'] === $userId || (int) $row['receiver_id'] === $userId)) {
                            $hide = lex_pdo()->prepare(
                                'INSERT IGNORE INTO message_deletions (message_id, user_id) VALUES (:message_id, :user_id)'
                            );
                            if ($unsend && (int) $row['sender_id'] === $userId) {
                                $hide->execute(['message_id' => $messageId, 'user_id' => (int) $row['sender_id']]);
                                $hide->execute(['message_id' => $messageId, 'user_id' => (int) $row['receiver_id']]);
                                lex_audit('unsend_message', 'messages', (string) $messageId);
                            } else {
                                $hide->execute(['message_id' => $messageId, 'user_id' => $userId]);
                                lex_audit('delete_message', 'messages', (string) $messageId);
                            }
                        }
                    }
                    $otherId = $returnTo;
                    if ($otherId <= 0 && !empty($row) && is_array($row)) {
                        $otherId = (int) $row['sender_id'] === $userId
                            ? (int) $row['receiver_id']
                            : (int) $row['sender_id'];
                    }
                    $stillVisible = $otherId > 0 ? lex_messages_thread($userId, $otherId) : [];
                    $target = $stillVisible !== [] && $otherId > 0 ? 'chat.php?with=' . $otherId : 'chat.php';
                    if (function_exists('lex_redirect_app_file')) {
                        lex_redirect_app_file($target);
                    }
                    header('Location: ' . (function_exists('lex_nav_href') ? lex_nav_href($target) : lex_app_url($target)));
                    exit;
                }

                if ($action === 'send') {
                    $recipientId = lex_sanitize_int($_POST['new_recipient'] ?? $_POST['recipient_id'] ?? 0);
                    $caseId = lex_sanitize_int($_POST['case_id'] ?? 0);
                    $body = lex_sanitize_multiline_text($_POST['message'] ?? '');
                    $important = lex_sanitize_bool($_POST['important'] ?? 0);

                    $allowed = lex_messages_allowed_recipients($user);
                    $recipient = lex_messages_can_contact($allowed, $recipientId);

                    $limit = lex_rate_limit_hit('send_message', lex_rate_limit_key(lex_rate_limit_user_part()), 60, 3600, 0);

                    $attachment = null;
                    try {
                        $attachment = lex_messages_store_attachment($_FILES['attachment'] ?? []);
                    } catch (RuntimeException $e) {
                        $error = $e->getMessage();
                    }

                    if ($error === '' && !$limit['allowed']) {
                        $error = lex_rate_limit_message((int) $limit['retry_after']);
                    } elseif ($error === '' && !$recipient) {
                        $error = 'Choose a valid recipient.';
                    } elseif ($error === '' && !lex_messages_roles_may_chat((string) ($user['role'] ?? ''), (string) ($recipient['role'] ?? ''))) {
                        $error = 'You cannot message this person.';
                    } elseif ($error === '' && $body === '' && !$attachment) {
                        $error = 'Write a message or attach a file.';
                    } elseif ($error === '' && $body !== '' && function_exists('lex_phishing_scan_message_body')) {
                        $phishingError = lex_phishing_scan_message_body($body);
                        if ($phishingError !== null) {
                            $error = $phishingError;
                        }
                    }
                    if ($error === '') {
                        try {
                            $storedBody = null;
                            $bodyAlg = null;
                            $bodyIv = null;
                            $bodyTag = null;
                            if ($body !== '') {
                                $encryptedBody = lex_messages_encrypt($body);
                                $storedBody = base64_encode($encryptedBody['ciphertext']);
                                $bodyAlg = $encryptedBody['algorithm'];
                                $bodyIv = $encryptedBody['iv'];
                                $bodyTag = $encryptedBody['tag'];
                            }
                            $stmt = lex_pdo()->prepare(
                                'INSERT INTO messages (sender_id, receiver_id, case_id, body, body_encryption_algorithm, body_encryption_iv, body_encryption_tag, attachment_original_name, attachment_stored_name, attachment_mime_type, attachment_size, attachment_encryption_algorithm, attachment_encryption_iv, attachment_encryption_tag, is_important)
                                 VALUES (:sender_id, :receiver_id, :case_id, :body, :body_encryption_algorithm, :body_encryption_iv, :body_encryption_tag, :attachment_original_name, :attachment_stored_name, :attachment_mime_type, :attachment_size, :attachment_encryption_algorithm, :attachment_encryption_iv, :attachment_encryption_tag, :is_important)'
                            );
                            $stmt->execute([
                                'sender_id' => $userId,
                                'receiver_id' => $recipientId,
                                'case_id' => $caseId > 0 ? $caseId : null,
                                'body' => $storedBody,
                                'body_encryption_algorithm' => $bodyAlg,
                                'body_encryption_iv' => $bodyIv,
                                'body_encryption_tag' => $bodyTag,
                                'attachment_original_name' => $attachment['original_name'] ?? null,
                                'attachment_stored_name' => $attachment['stored_name'] ?? null,
                                'attachment_mime_type' => $attachment['mime_type'] ?? null,
                                'attachment_size' => $attachment['size'] ?? null,
                                'attachment_encryption_algorithm' => $attachment['encryption_algorithm'] ?? null,
                                'attachment_encryption_iv' => $attachment['encryption_iv'] ?? null,
                                'attachment_encryption_tag' => $attachment['encryption_tag'] ?? null,
                                'is_important' => $important,
                            ]);
                            lex_audit('send_message', 'messages', (string) $recipientId);
                            lex_notify($recipientId, 'message', 'New message from ' . (string) $user['full_name']);
                            if (function_exists('lex_email_notify_message')) {
                                lex_email_notify_message($recipientId, (string) $user['full_name']);
                            }
                            if (function_exists('lex_redirect_app_file')) {
                                lex_redirect_app_file('chat.php?with=' . $recipientId);
                            }
                            header('Location: ' . (function_exists('lex_nav_href') ? lex_nav_href('chat.php?with=' . $recipientId) : lex_app_url('chat.php?with=' . $recipientId)));
                            exit;
                        } catch (Throwable $e) {
                            $error = 'The message could not be saved. Try again.';
                        }
                    }
                }
            }
        }

        $requestedOtherId = lex_sanitize_int($_GET['with'] ?? 0);
        $conversations = lex_messages_conversations($userId);
        $allowedRecipients = lex_messages_allowed_recipients($user);
        $allowedIds = [];
        foreach ($allowedRecipients as $person) {
            $allowedIds[(int) ($person['id'] ?? 0)] = true;
        }
        $viewerRole = (string) ($user['role'] ?? '');
        $conversations = array_values(array_filter(
            $conversations,
            static function (array $conversation) use ($allowedIds, $viewerRole): bool {
                $otherId = (int) ($conversation['other_id'] ?? 0);
                if ($otherId <= 0 || !isset($allowedIds[$otherId])) {
                    return false;
                }

                return lex_messages_roles_may_chat($viewerRole, (string) ($conversation['other_role'] ?? ''));
            }
        ));
        $conversations = lex_messages_seed_conversations($conversations, $allowedRecipients, $userId);

        if ($requestedOtherId > 0 && !isset($allowedIds[$requestedOtherId])) {
            $requestedOtherId = 0;
        }

        // Desktop still previews the most recent thread in the right pane, but
        // phones/tablets only enter the single-thread view when ?with= is set.
        // Otherwise the back control would loop: list → auto-open → back → list.
        $activeOtherId = $requestedOtherId;
        if ($activeOtherId === 0 && !empty($conversations)) {
            $activeOtherId = (int) $conversations[0]['other_id'];
        }
        $explicitThread = $requestedOtherId > 0;

        $activeRecipient = null;
        foreach ($conversations as $conversation) {
            if ((int) $conversation['other_id'] === $activeOtherId) {
                $activeRecipient = $conversation;
                break;
            }
        }
        if (!$activeRecipient && $activeOtherId > 0 && isset($allowedIds[$activeOtherId])) {
            $stmt = lex_pdo()->prepare('SELECT id, full_name, role, avatar_stored_name FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $activeOtherId]);
            $row = $stmt->fetch();
            if ($row) {
                $activeRecipient = ['other_id' => $row['id'], 'other_name' => $row['full_name'], 'other_role' => $row['role'], 'other_avatar' => $row['avatar_stored_name']];
            }
        }

        if ($activeOtherId > 0 && isset($allowedIds[$activeOtherId])) {
            lex_pdo()->prepare('UPDATE messages SET is_read = 1 WHERE receiver_id = :viewer AND sender_id = :other AND is_read = 0')
                ->execute(['viewer' => $userId, 'other' => $activeOtherId]);
        }

        $canMessageActive = $activeOtherId > 0 && $activeRecipient
            && lex_messages_can_contact($allowedRecipients, $activeOtherId)
            && lex_messages_roles_may_chat((string) ($user['role'] ?? ''), (string) ($activeRecipient['other_role'] ?? ''));
        $thread = $canMessageActive ? lex_messages_thread($userId, $activeOtherId) : [];
        $threadImportant = is_array($activeRecipient) && !empty($activeRecipient['last_important']);
        if (!$threadImportant) {
            foreach ($thread as $threadMessage) {
                if (!empty($threadMessage['is_important'])) {
                    $threadImportant = true;
                    break;
                }
            }
        }

        $searchQuery = trim(lex_sanitize_text($_GET['search'] ?? ''));
        $searchResults = [];
        if ($searchQuery !== '' && mb_strlen($searchQuery) >= 2) {
            try {
                $searchStmt = lex_pdo()->prepare(
                    "SELECT m.id, m.sender_id, m.receiver_id, m.body, m.created_at,
                            m.body_encryption_algorithm,
                            CASE WHEN m.sender_id = :uid THEN m.receiver_id ELSE m.sender_id END AS other_id,
                            u.full_name AS other_name
                     FROM messages m
                     JOIN users u ON u.id = CASE WHEN m.sender_id = :uid2 THEN m.receiver_id ELSE m.sender_id END
                     LEFT JOIN message_deletions md ON md.message_id = m.id AND md.user_id = :uid3
                     WHERE (m.sender_id = :uid4 OR m.receiver_id = :uid5)
                       AND md.id IS NULL
                       AND (m.body_encryption_algorithm IS NULL OR m.body_encryption_algorithm = '')
                       AND LOWER(m.body) LIKE :q
                     ORDER BY m.created_at DESC
                     LIMIT 30"
                );
                $searchStmt->execute([
                    'uid' => $userId, 'uid2' => $userId, 'uid3' => $userId,
                    'uid4' => $userId, 'uid5' => $userId,
                    'q' => '%' . mb_strtolower($searchQuery) . '%',
                ]);
                $searchResults = $searchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $searchResults = [];
            }
        }
        $recipientGroups = ['admin' => [], 'lawyer' => [], 'client' => []];
        foreach ($allowedRecipients as $recipient) {
            $group = (string) ($recipient['role'] ?? 'client');
            if (!isset($recipientGroups[$group])) {
                $group = 'client';
            }
            $recipientGroups[$group][] = $recipient;
        }
        $callHref = $canMessageActive && function_exists('lex_inbox_call_href')
            ? lex_inbox_call_href($activeOtherId)
            : '';

        lex_page_header('Messages', 'messages', $user);
        ?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= lex_e($error) ?></div><?php endif; ?>
<section class="messages-layout inbox-messenger" data-chat-shell data-active-conversation="<?= $explicitThread ? '1' : '0' ?>">
  <aside class="chat-sidebar">
    <div class="inbox-sidebar-head">
      <h2 class="inbox-title">Chats</h2>
    </div>
    <button id="inboxComposeBtn" class="inbox-new-btn inbox-new-btn-wide" type="button" data-modal-open="newMessageModal" title="New message" aria-label="New message" onclick="if (event) { event.preventDefault(); } if (window.lexOpenNewMessage) { return window.lexOpenNewMessage(event); } var d=document.getElementById('newMessageModal'); if (d) { if (d.showModal && !d.open) { d.showModal(); } else { d.setAttribute('open',''); } d.classList.add('is-open'); d.setAttribute('aria-hidden','false'); } return false;">
      <span aria-hidden="true">✎</span>
      <span class="inbox-new-btn-label">New message</span>
    </button>
    <input type="search" class="inbox-search" data-conversation-search placeholder="Search chats…">
    <form method="get" action="<?= lex_e(function_exists('lex_nav_href') ? lex_nav_href('chat.php') : lex_app_url('chat.php')) ?>" class="inbox-search-form" style="display:flex;gap:4px;padding:0 0 0.35rem;">
      <input type="search" name="search" class="inbox-search" placeholder="Search all messages…" value="<?= lex_e(lex_sanitize_text($_GET['search'] ?? '')) ?>" style="flex:1;">
      <button class="button button-secondary" type="submit" style="min-height:36px;padding:0 0.6rem;">🔍</button>
    </form>
    <div class="header-actions inbox-filters" role="tablist">
      <button type="button" class="button button-secondary is-active" data-filter-tab="all">All</button>
      <button type="button" class="button button-secondary" data-filter-tab="unread">Unread</button>
      <button type="button" class="button button-secondary" data-filter-tab="important">Important</button>
    </div>
    <?php if (lex_messages_lock_applies($user)): ?>
      <div class="inbox-pin-row">
        <a class="button button-secondary" href="<?= lex_e(lex_messages_lock_url($user, ['change' => 1])) ?>">Change PIN</a>
        <form method="post" class="messages-lock-now-form">
          <?= lex_csrf_field() ?>
          <input type="hidden" name="action" value="lock_messages">
          <button class="button button-secondary" type="submit">Lock</button>
        </form>
      </div>
    <?php endif; ?>
    <div class="conversation-group">
      <div class="conversation-list-scroll" data-chat-scroll>
        <?php foreach ($conversations as $conversation): ?>
          <?php lex_messages_render_conversation_item($conversation, $activeOtherId, $expectedRole); ?>
        <?php endforeach; ?>
        <?php if (!$conversations): ?><p class="muted" style="padding:0.75rem;">No chats yet.</p><?php endif; ?>
      </div>
    </div>
  </aside>

  <div class="chat-panel">
    <header class="chat-header">
      <div class="chat-header-title">
        <a class="icon-button chat-back-link" href="<?= lex_e(function_exists('lex_nav_href') ? lex_nav_href('chat.php') : lex_app_url('chat.php')) ?>" aria-label="Back to conversations" title="Back to conversations">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.2 4.8 8 12l7.2 7.2" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
        <div class="inbox-header-copy">
          <h2><?= $activeRecipient ? lex_e((string) $activeRecipient['other_name']) : 'Select a chat' ?></h2>
          <?php if ($activeRecipient):
            $otherOnline = false;
            try {
                $onlineStmt = lex_pdo()->prepare('SELECT last_login FROM users WHERE id = :id LIMIT 1');
                $onlineStmt->execute(['id' => $activeOtherId]);
                $ll = $onlineStmt->fetchColumn();
                $otherOnline = $ll && (new DateTimeImmutable((string) $ll)) >= (new DateTimeImmutable())->modify('-5 minutes');
            } catch (Throwable $e) { $otherOnline = false; }
          ?>
            <span class="status-line<?= $otherOnline ? ' is-active-now' : '' ?>"><?= $otherOnline ? 'Active now' : 'Offline' ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($activeRecipient && $activeOtherId > 0): ?>
        <div class="inbox-header-actions">
          <?php if ($callHref !== ''): ?>
            <a class="inbox-head-icon inbox-vc-btn" href="<?= lex_e($callHref) ?>" title="Video call" aria-label="Video call" style="display:inline-flex;min-width:44px;min-height:44px;align-items:center;justify-content:center;cursor:pointer;pointer-events:auto;position:relative;z-index:6;touch-action:manipulation;box-sizing:border-box;flex:0 0 auto;">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 10.5 21 7v10l-4-3.5V16a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2.5Z" fill="currentColor"/></svg>
              <span class="inbox-vc-label">Video call</span>
            </a>
          <?php endif; ?>
          <button class="inbox-head-icon inbox-head-phishing phishing-detector-trigger" type="button" title="Phishing detection" aria-label="Phishing detection" style="display:inline-flex;min-width:44px;min-height:44px;align-items:center;justify-content:center;cursor:pointer;pointer-events:auto;position:relative;z-index:6;touch-action:manipulation;box-sizing:border-box;flex:0 0 auto;" onclick="<?= lex_e(function_exists('lex_phishing_open_onclick') ? lex_phishing_open_onclick() : 'return false;') ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5Z" fill="currentColor"/></svg>
            <span class="inbox-vc-label">Phishing detection</span>
          </button>
          <form method="post" class="inbox-header-important-form" data-no-loading>
            <?= lex_csrf_field() ?>
            <input type="hidden" name="action" value="toggle_important">
            <input type="hidden" name="with" value="<?= (int) $activeOtherId ?>">
            <button class="inbox-head-icon inbox-head-important<?= $threadImportant ? ' is-important' : '' ?>" type="submit" title="<?= $threadImportant ? 'Remove important mark' : 'Mark as important' ?>" aria-label="<?= $threadImportant ? 'Remove important mark' : 'Mark as important' ?>" aria-pressed="<?= $threadImportant ? 'true' : 'false' ?>" style="display:inline-flex;min-width:44px;min-height:44px;align-items:center;justify-content:center;cursor:pointer;pointer-events:auto;position:relative;z-index:6;touch-action:manipulation;box-sizing:border-box;flex:0 0 auto;">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm0 15.25A1.25 1.25 0 1 1 13.25 16 1.25 1.25 0 0 1 12 17.25ZM13.1 13.6h-2.2V6.5h2.2Z" fill="currentColor"/></svg>
              <span class="inbox-vc-label"><?= $threadImportant ? 'Remove important mark' : 'Mark as important' ?></span>
            </button>
          </form>
        </div>
      <?php endif; ?>
    </header>
    <div class="chat-feed" data-chat-scroll>
      <?php if ($searchQuery !== '' && !empty($searchResults)): ?>
        <div style="padding:0.75rem;background:rgba(0,132,255,0.06);border-bottom:1px solid var(--border);">
          <strong>Search results for "<?= lex_e($searchQuery) ?>"</strong> (<?= count($searchResults) ?> found)
          <?php foreach ($searchResults as $sr): ?>
            <div style="padding:0.4rem 0;border-bottom:1px solid var(--border);">
              <a href="<?= lex_e(function_exists('lex_nav_href') ? lex_nav_href('chat.php?with=' . (int) $sr['other_id']) : lex_app_url('chat.php?with=' . (int) $sr['other_id'])) ?>" style="font-weight:600;color:var(--accent);"><?= lex_e((string) $sr['other_name']) ?></a>
              <span class="muted" style="font-size:0.78rem;"><?= lex_e(lex_message_timestamp((string) $sr['created_at'])) ?></span>
              <p style="margin:0.15rem 0 0;font-size:0.88rem;"><?= lex_e(mb_substr((string) $sr['body'], 0, 120)) ?><?= mb_strlen((string) $sr['body']) > 120 ? '…' : '' ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php elseif ($searchQuery !== ''): ?>
        <div style="padding:0.75rem;background:rgba(0,132,255,0.06);border-bottom:1px solid var(--border);">
          <p class="muted">No messages found for "<?= lex_e($searchQuery) ?>".</p>
        </div>
      <?php endif; ?>
      <?php foreach ($thread as $message): ?>
        <?php
          $isSent = (int) $message['sender_id'] === $userId;
          $senderRole = (string) $message['sender_role'];
        ?>
        <div class="message-row <?= $isSent ? 'sent' : 'received' ?>">
          <div class="chat-bubble <?= $isSent ? 'sent' : 'received' ?> role-<?= lex_e($senderRole) ?>">
            <?php if (!empty($message['body'])): ?><p class="bubble-text"><?= nl2br(lex_e((string) $message['body'])) ?></p><?php endif; ?>
            <?php if (!empty($message['attachment_stored_name'])): ?>
              <?php
                $attachName = (string) ($message['attachment_original_name'] ?? 'Attachment');
                $attachMime = strtolower((string) ($message['attachment_mime_type'] ?? ''));
                $attachId = (int) $message['id'];
                $canPreview = function_exists('lex_messages_previewable_mime')
                    && lex_messages_previewable_mime($attachMime, $attachName);
                $viewUrl = function_exists('lex_messages_view_url')
                    ? lex_messages_view_url($attachId)
                    : lex_app_url('message_view.php?id=' . $attachId);
                $previewUrl = function_exists('lex_messages_attachment_url')
                    ? lex_messages_attachment_url($attachId, true)
                    : lex_app_url('message_attachment.php?id=' . $attachId . '&preview=1');
                $downloadUrl = function_exists('lex_messages_attachment_url')
                    ? lex_messages_attachment_url($attachId, false)
                    : lex_app_url('message_attachment.php?id=' . $attachId);
                $attachExt = strtolower((string) pathinfo($attachName, PATHINFO_EXTENSION));
                $isImage = str_starts_with($attachMime, 'image/') || in_array($attachExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                $isVideo = str_starts_with($attachMime, 'video/') || in_array($attachExt, ['mp4', 'm4v', 'webm', 'mov'], true);
                $downloadLabel = 'Download ' . $attachName;
                $downloadIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16.5 6.5 11h3.25V4h4.5v7H17.5L12 16.5ZM5 20v-2h14v2H5Z" fill="currentColor"/></svg>';
              ?>
              <?php if ($isImage && $canPreview): ?>
                <div class="inbox-media inbox-media--image">
                  <a class="inbox-media-open" href="<?= lex_e($viewUrl) ?>">
                    <img src="<?= lex_e($previewUrl) ?>" alt="<?= lex_e($attachName) ?>">
                  </a>
                  <a class="inbox-media-download" href="<?= lex_e($downloadUrl) ?>" download="<?= lex_e($attachName) ?>" title="Download" aria-label="<?= lex_e($downloadLabel) ?>"><?= $downloadIcon ?></a>
                </div>
              <?php elseif ($isVideo && $canPreview): ?>
                <div class="inbox-media inbox-media--video">
                  <video src="<?= lex_e($previewUrl) ?>" controls playsinline preload="metadata"></video>
                  <a class="inbox-media-download" href="<?= lex_e($downloadUrl) ?>" download="<?= lex_e($attachName) ?>" title="Download" aria-label="<?= lex_e($downloadLabel) ?>"><?= $downloadIcon ?></a>
                </div>
              <?php else: ?>
                <div class="inbox-file">
                  <a class="inbox-file-main" href="<?= lex_e($canPreview ? $viewUrl : $downloadUrl) ?>">
                    <span class="inbox-file-icon" aria-hidden="true"></span>
                    <span class="inbox-file-copy">
                      <strong><?= lex_e($attachName) ?></strong>
                      <small><?= $canPreview ? 'Open file' : 'File' ?></small>
                    </span>
                  </a>
                  <a class="inbox-media-download" href="<?= lex_e($downloadUrl) ?>" download="<?= lex_e($attachName) ?>" title="Download" aria-label="<?= lex_e($downloadLabel) ?>"><?= $downloadIcon ?></a>
                </div>
              <?php endif; ?>
            <?php endif; ?>
            <div class="bubble-meta">
              <span><?= lex_e(lex_message_timestamp((string) $message['created_at'])) ?></span>
              <?php if ($isSent): ?>
                <?php $isRead = !empty($message['is_read']); ?>
                <span class="read-receipt<?= $isRead ? ' is-seen' : '' ?>"><?= $isRead ? '✓ Seen' : '✓ Sent' ?></span>
              <?php endif; ?>
            </div>
            <div class="message-delete-form">
              <form method="post" data-no-loading onsubmit="return confirm('Remove this message from your inbox?');">
                <?= lex_csrf_field() ?>
                <input type="hidden" name="action" value="delete_message">
                <input type="hidden" name="message_id" value="<?= (int) $message['id'] ?>">
                <input type="hidden" name="with" value="<?= (int) $activeOtherId ?>">
                <button class="message-delete-button" type="submit" title="Delete for you" aria-label="Delete message">&times;</button>
              </form>
              <?php if ($isSent): ?>
              <form method="post" data-no-loading onsubmit="return confirm('Unsend this message for both of you?');">
                <?= lex_csrf_field() ?>
                <input type="hidden" name="action" value="delete_message">
                <input type="hidden" name="unsend" value="1">
                <input type="hidden" name="message_id" value="<?= (int) $message['id'] ?>">
                <input type="hidden" name="with" value="<?= (int) $activeOtherId ?>">
                <button class="message-unsend-button" type="submit" title="Unsend">Unsend</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if ($activeOtherId > 0 && !$thread && $searchQuery === ''): ?><p class="inbox-empty">No messages yet. Say hello!</p><?php endif; ?>
      <?php
      if ($activeOtherId > 0 && function_exists('lex_inbox_call_tables_ensure')) {
          try {
              lex_inbox_call_tables_ensure();
              [$callLow, $callHigh] = lex_inbox_call_pair($userId, $activeOtherId);
              $callHistStmt = lex_pdo()->prepare(
                  "SELECT status, started_at, ended_at FROM message_call_sessions
                   WHERE user_low_id = :low AND user_high_id = :high AND started_at IS NOT NULL
                   ORDER BY started_at DESC LIMIT 5"
              );
              $callHistStmt->execute(['low' => $callLow, 'high' => $callHigh]);
              $callHist = $callHistStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
              if ($callHist):
      ?>
        <div class="inbox-call-history">
          <strong>Recent calls</strong>
          <?php foreach ($callHist as $ch): ?>
            <div class="inbox-call-history-row">
              <span aria-hidden="true">📞</span>
              <span><?= lex_e((new DateTimeImmutable((string) $ch['started_at']))->format('M j, g:i A')) ?></span>
              <span class="muted"><?= lex_e(ucfirst((string) $ch['status'])) ?></span>
              <?php if (!empty($ch['ended_at']) && !empty($ch['started_at'])):
                  $dur = (new DateTimeImmutable((string) $ch['started_at']))->diff(new DateTimeImmutable((string) $ch['ended_at']));
                  $mins = $dur->i + ($dur->h * 60);
                  $secs = $dur->s;
              ?>
                <span class="muted"><?= $mins ?>m <?= $secs ?>s</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; } catch (Throwable $e) {} } ?>
    </div>
    <?php if ($canMessageActive && $activeOtherId > 0): ?>
    <div class="typing-indicator" data-typing-indicator hidden></div>
    <?php endif; ?>
    <?php if ($canMessageActive): ?>
    <form method="post" action="<?= lex_e(function_exists('lex_nav_href') ? lex_nav_href('chat.php') : 'chat.php') ?>" class="chat-composer" enctype="multipart/form-data" data-no-loading data-inbox-composer>
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="send">
      <input type="hidden" name="recipient_id" value="<?= (int) $activeOtherId ?>">
      <div data-attachment-preview hidden></div>
      <div class="composer-row">
        <div class="composer-tools">
          <label class="icon-button ghost inbox-attach-btn" title="Attach a photo, video, or file">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2Z" fill="currentColor"/></svg>
            <input type="file" name="attachment" accept="image/*,video/*,.pdf,.txt,.doc,.docx" data-attachment-input hidden>
          </label>
        </div>
        <textarea class="composer-input" name="message" rows="1" placeholder="Aa"></textarea>
        <button class="inbox-send-btn" type="submit" title="Send" aria-label="Send">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.4 20.6 21 12 3.4 3.4 3 10.25l11.2 1.75L3 13.75Z" fill="currentColor"/></svg>
        </button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</section>

<dialog class="inbox-new-message-modal" id="newMessageModal" data-modal closedby="any" aria-labelledby="newMessageTitle">
  <div class="modal-card">
    <div class="modal-header">
      <h2 id="newMessageTitle">New message</h2>
      <button class="icon-button inbox-modal-close" type="button" data-modal-close aria-label="Close" onclick="if (event) { event.preventDefault(); } if (window.lexCloseNewMessage) { return window.lexCloseNewMessage(event); } var d=document.getElementById('newMessageModal'); if (d) { if (d.close) { d.close(); } else { d.removeAttribute('open'); } d.classList.remove('is-open'); d.setAttribute('aria-hidden','true'); } return false;">&times;</button>
    </div>
    <form method="post" action="<?= lex_e(function_exists('lex_nav_href') ? lex_nav_href('chat.php') : 'chat.php') ?>" class="modal-body stack-form" enctype="multipart/form-data" data-new-message-form data-no-loading>
      <?= lex_csrf_field() ?>
      <input type="hidden" name="action" value="send">
      <input type="hidden" name="case_id" value="0" data-default-case-input>
      <input type="hidden" name="new_recipient_role" data-recipient-role>
      <label>To
        <select name="new_recipient" data-recipient-select required>
          <option value="">Select a person…</option>
          <?php foreach (['admin' => 'Admins', 'lawyer' => 'Attorneys', 'client' => 'Clients'] as $groupKey => $groupLabel): ?>
            <?php if (!empty($recipientGroups[$groupKey])): ?>
              <optgroup label="<?= lex_e($groupLabel) ?>">
                <?php foreach ($recipientGroups[$groupKey] as $recipient): ?>
                  <option value="<?= (int) $recipient['id'] ?>" data-role="<?= lex_e((string) $recipient['role']) ?>" data-case-id="<?= (int) $recipient['case_id'] ?>"><?= lex_e((string) $recipient['full_name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Message
        <textarea name="message" rows="4" data-message-input placeholder="Write your message…"></textarea>
      </label>
      <label class="full">Attachment
        <input type="file" data-modal-attachment-input name="attachment" accept="image/*,video/*,.pdf,.txt,.doc,.docx">
        <div data-modal-attachment-name class="muted">No file selected</div>
      </label>
      <div class="alert alert-error" data-modal-errors hidden></div>
      <button class="button button-primary" type="submit">Send message</button>
    </form>
  </div>
</dialog>
        <?php
        lex_page_footer();
    }
}
