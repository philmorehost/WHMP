<?php

declare(strict_types=1);

// Moving a client from one provider to another — a reseller store to another store,
// a store to the platform, or the platform to a store — and the record of every move.
//
// ONE TABLE FOR THE REQUEST AND THE AUDIT TRAIL
//
// A move starts life as a REQUEST (a client or a reseller asks, through a support
// ticket) and ends as a DECISION (a super admin moves the account, or declines). An
// admin can also move an account directly, with no request at all. All three are the
// same row in different states, so the history of "who asked, who decided, what moved"
// is in one place rather than split across two tables that have to be joined to make
// sense:
//
//   pending    asked for, nobody has decided
//   completed  the account was moved; `summary` records what moved with it
//   rejected   declined, with the admin's reason in decision_note
//   cancelled  withdrawn by whoever asked
//
// THE TARGET IS RECORDED TWICE, ON PURPOSE
//
// `to_reseller_id` NULL means "the platform" only when `target` says 'platform'. A
// store that is later deleted sets its id NULL here (ON DELETE SET NULL, because an
// audit row must outlive what it describes), and without `target` that row would then
// silently read as "moved to the platform". `target_label` keeps the name the store
// had when the decision was made, for the same reason.
//
// NOTHING HERE TOUCHES MONEY. What a move changes (clients.reseller_id and the
// client's tickets) is applied by ClientMigrationService; the earnings a store already
// made — its ledger, cost bills, payouts and statements — stay exactly where they were
// earned. See that class for why.

return [
    'up' => [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS client_migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NULL,
            client_email VARCHAR(191) NOT NULL,
            from_reseller_id INT UNSIGNED NULL,
            from_label VARCHAR(191) NULL,
            target ENUM('platform','store') NOT NULL,
            to_reseller_id INT UNSIGNED NULL,
            target_label VARCHAR(191) NULL,
            target_input VARCHAR(255) NULL,
            requested_by ENUM('client','reseller','admin') NOT NULL,
            requester_client_id INT UNSIGNED NULL,
            ticket_id INT UNSIGNED NULL,
            reason TEXT NULL,
            status ENUM('pending','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
            admin_id INT UNSIGNED NULL,
            decision_note TEXT NULL,
            summary TEXT NULL,
            decided_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_client_migrations_status (status, created_at),
            INDEX idx_client_migrations_client (client_id),
            INDEX idx_client_migrations_requester (requester_client_id),
            CONSTRAINT fk_client_migrations_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
            CONSTRAINT fk_client_migrations_from FOREIGN KEY (from_reseller_id) REFERENCES resellers(id) ON DELETE SET NULL,
            CONSTRAINT fk_client_migrations_to FOREIGN KEY (to_reseller_id) REFERENCES resellers(id) ON DELETE SET NULL,
            CONSTRAINT fk_client_migrations_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL,

        // The three messages a move sends. INSERT IGNORE rather than an upsert: these
        // are admin-editable, and a re-applied migration must never overwrite a
        // template somebody has reworded.
        //
        // {{company_name}} on the client's message is the NEW provider's name — the
        // dispatcher writes it as that store (or as us, for a move to the platform).
        <<<'SQL'
        INSERT IGNORE INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'client_account_moved',
            'Client Account Moved (to client)',
            'Your account is now with {{company_name}}',
            '<p>Hi {{first_name}},</p><p>Your account has been moved to <strong>{{company_name}}</strong>, as requested. Everything came with it: your services, domains, invoices and support history.</p><p>From now on, please sign in at <a href="{{login_url}}">{{login_url}}</a> with your usual email address and password.</p><p>Thanks,<br>{{company_name}}</p>',
            NOW(),
            NOW()
        )
        SQL,
        <<<'SQL'
        INSERT IGNORE INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'reseller_client_moved_in',
            'Reseller: Customer Moved To Your Store',
            'A customer account has been moved to {{store_name}}',
            '<p>Hi {{first_name}},</p><p>The customer account <strong>{{client_email}}</strong> has been moved to your store, <strong>{{store_name}}</strong>, including its services, domains, invoices and support history.</p><p>Their tickets are now on your support desk, and new orders they place will be credited to your store.</p><p>Thanks,<br>{{company_name}}</p>',
            NOW(),
            NOW()
        )
        SQL,
        <<<'SQL'
        INSERT IGNORE INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'reseller_client_moved_out',
            'Reseller: Customer Moved Away From Your Store',
            'A customer account has left {{store_name}}',
            '<p>Hi {{first_name}},</p><p>The customer account <strong>{{client_email}}</strong> has been moved away from your store, <strong>{{store_name}}</strong>.</p><p>Earnings you have already made from this customer stay on your account. Their tickets have left your support desk, and their future orders will no longer be credited to your store.</p><p>Thanks,<br>{{company_name}}</p>',
            NOW(),
            NOW()
        )
        SQL,
    ],
];
