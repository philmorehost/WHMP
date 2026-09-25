<?php
/** @var array<int, array<string, mixed>> $departments */
/** @var array<int, array<string, mixed>> $services the client's own services, for the picker */
/** @var array<int, array<string, mixed>> $domains the client's own domains, for the picker */
/** @var int|null $selectedServiceId preselected by an "open a ticket about this" link */
/** @var int|null $selectedDomainId preselected by an "open a ticket about this" link */
/** @var bool $limitReached */
/** @var int $maxOpenTickets */
$selectedServiceId ??= null;
$selectedDomainId ??= null;
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <h1 class="cv-card__title">Open New Ticket</h1>
    <p><a href="/client/tickets">&larr; Back to tickets</a></p>

    <?php if (!empty($limitReached)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-4);padding:var(--cv-space-3);border-radius:8px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3);color:#dc2626;">
            You already have <strong><?= (int) ($maxOpenTickets ?? 5) ?></strong> open tickets.
            Please wait for one of them to be resolved, or reply to an existing ticket,
            before opening a new one.
        </div>
    <?php endif; ?>

    <form method="post" action="/client/tickets" enctype="multipart/form-data"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Department</label>
            <select class="cv-input" name="department_id" required>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="cv-field">
            <label class="cv-label">Subject</label>
            <input class="cv-input" name="subject" required>
        </div>

        <?php
        // Naming the item the ticket is about is what lets support skip the
        // "which hosting is down?" round-trip — and what lets the admin ticket
        // page link straight to the service/domain for review. Optional: a
        // billing question or a general enquiry isn't about one item.
        ?>
        <div style="margin:var(--cv-space-4) 0;padding:var(--cv-space-3);border:1px solid var(--cv-border-default);border-radius:8px;">
            <p style="margin:0 0 var(--cv-space-3);font-weight:700;font-size:.9rem;">
                What is this about? <span style="color:var(--cv-text-secondary);font-weight:400;">(optional)</span>
            </p>

            <div class="cv-field">
                <label class="cv-label">Related Service</label>
                <select class="cv-input" name="service_id">
                    <option value="">— Not about a specific service —</option>
                    <?php foreach ($services as $service): ?>
                        <?php
                        $serviceLabel = (string) ($service['product_name'] ?? 'Service');
                        $serviceDomain = trim((string) ($service['domain'] ?? ''));
                        if ($serviceDomain !== '') {
                            $serviceLabel .= ' — ' . $serviceDomain;
                        }
                        $serviceLabel .= ' (' . (string) ($service['status'] ?? '') . ')';
                        ?>
                        <option value="<?= (int) $service['id'] ?>" <?= $selectedServiceId === (int) $service['id'] ? 'selected' : '' ?>><?= e($serviceLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($services === []): ?>
                    <p style="margin:6px 0 0;font-size:.8rem;color:var(--cv-text-secondary);">You have no services on file.</p>
                <?php endif; ?>
            </div>

            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Related Domain</label>
                <select class="cv-input" name="domain_id">
                    <option value="">— Not about a specific domain —</option>
                    <?php foreach ($domains as $domain): ?>
                        <?php $domainLabel = (string) ($domain['domain_name'] ?? 'Domain') . ' (' . (string) ($domain['status'] ?? '') . ')'; ?>
                        <option value="<?= (int) $domain['id'] ?>" <?= $selectedDomainId === (int) $domain['id'] ? 'selected' : '' ?>><?= e($domainLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($domains === []): ?>
                    <p style="margin:6px 0 0;font-size:.8rem;color:var(--cv-text-secondary);">You have no domains on file.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="cv-field">
            <label class="cv-label">Message</label>
            <textarea class="cv-input" name="message" rows="6" required></textarea>
        </div>
        <div class="cv-field">
            <label class="cv-label">Attachments <span style="color:var(--cv-text-secondary);font-weight:400;">(optional — images &amp; documents, up to 10 MB each)</span></label>
            <input class="cv-input" type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.zip">
        </div>
        <button class="cv-btn" type="submit">Submit Ticket</button>
    </form>
</div>
