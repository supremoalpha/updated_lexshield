# Blockchain-backed Data Sharing

Lawyers often need to share a case file with a colleague, but that
shouldn't be a silent, unaudited action. This feature adds an
admin-approved, tamper-evident workflow for exactly that:

1. **Lawyer A** opens **Data Sharing** (`lawyer/data_sharing.php`), picks
   one of their own case files and another lawyer (**Lawyer B**), and
   submits a request.
2. The request is stored as `pending` in `data_sharing_requests`, and a
   `share_requested` block is appended to the **blockchain ledger**
   (`blockchain_ledger` table). All admins are notified.
3. An **admin** reviews the request at `admin/data_sharing.php` and
   **approves** or **rejects** it.
   - Approve -> a `case_file_shares` row grants Lawyer B read access to
     that case file's secure vault, and a `share_approved` block is
     appended to the ledger.
   - Reject -> a `share_rejected` block is appended; no access is
     granted.
4. Lawyer B now sees the case file under "Case files shared with me" on
   their own Data Sharing page and can open its encrypted vault from
   `case_files.php` - read-only, and only documents the owning lawyer has
   already approved.
5. Every step (request / approve / reject / cancel) is permanently
   recorded and viewable, in order, on `admin/blockchain_ledger.php`.

## What "blockchain" means here

This is a private, single-writer, application-level **hash chain** - the
same core idea a blockchain uses to make history tamper-evident, applied
directly to this app's own audit trail instead of a public distributed
network (which would be the wrong tool for an internal approval workflow
like this one).

Implementation: `config/blockchain/ledger.php`.

- Every event is stored as a **block** in `blockchain_ledger`:
  `block_index`, `event_type`, `actor_user_id`, `data_json` (the event
  payload), `previous_hash`, `hash`, `created_at`.
- Each block's `hash` is:

  ```
  sha256(block_index | previous_hash | created_at | event_type | data_json)
  ```

  Because every block's hash is derived from the block before it, editing
  *any* historical block (or deleting/reordering one) breaks every hash
  that comes after it.
- `lex_blockchain_add_block()` appends a new block inside a transaction
  with `SELECT ... FOR UPDATE` on a single-row lock table
  (`blockchain_ledger_lock`), so concurrent requests can never create two
  competing "next" blocks.
- `lex_blockchain_verify_chain()` walks every block in order, recomputes
  each hash, and checks the `previous_hash` linkage. The admin ledger page
  (`admin/blockchain_ledger.php`) runs this check on every page load and
  shows either "Chain intact" or exactly which block(s) were tampered
  with.

## Extending it

`lex_blockchain_add_block(string $eventType, array $data, ?int $actorUserId)`
is generic - any other high-value event in the app (e.g. admin password
resets, payment verification) could be logged to the same ledger the same
way, without adding a new table per feature.
