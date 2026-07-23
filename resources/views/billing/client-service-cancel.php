<?php
/** @var array<string, mixed> $service */
/** @var array<string, mixed> $currency */
$id = (int) $service['id'];
?>
<div class="cv-card" style="max-width:36rem;margin:0 auto;">
    <h1 class="cv-card__title">Request Cancellation: <?= e($service['product_name']) ?></h1>
    <p><a href="/client/services/<?= $id ?>">&larr; Back to service details</a></p>

    <form method="post" action="/client/services/<?= $id ?>/cancel" id="cancellation-form">
        <?= csrf_field() ?>

        <div class="cv-field" style="margin-bottom:var(--cv-space-4);">
            <label class="cv-label" style="font-weight:bold;">Cancellation Type</label>
            <div style="display:grid;gap:var(--cv-space-2);margin-top:var(--cv-space-2);">
                <label style="display:flex;align-items:flex-start;gap:var(--cv-space-2);cursor:pointer;">
                    <input type="radio" name="type" value="end_of_period" checked style="margin-top:4px;">
                    <div>
                        <strong>End of Billing Period (<?= e($service['next_due_date']) ?>)</strong>
                        <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:2px;">
                            The service will remain active and usable until your next due date. You will not receive any renewal invoices.
                        </div>
                    </div>
                </label>
                <label style="display:flex;align-items:flex-start;gap:var(--cv-space-2);cursor:pointer;margin-top:var(--cv-space-2);">
                    <input type="radio" name="type" value="immediate" style="margin-top:4px;">
                    <div>
                        <strong style="color:var(--cv-color-danger, #ef4444);">Immediate Cancellation</strong>
                        <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin-top:2px;">
                            The service will be terminated immediately. All files, databases, and configurations will be permanently deleted.
                        </div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Immediate Warning Box (Hidden by default until Immediate is selected) -->
        <div id="immediate-warning" style="display:none;background:rgba(239, 68, 68, 0.1);border-left:4px solid var(--cv-color-danger, #ef4444);padding:var(--cv-space-3);border-radius:4px;margin-bottom:var(--cv-space-4);">
            <strong style="color:var(--cv-color-danger, #ef4444);display:block;margin-bottom:4px;">⚠️ IRREVERSIBLE ACTION WARNING</strong>
            <p style="font-size:var(--cv-text-sm);margin:0;line-height:1.4;">
                Immediate cancellation cannot be reversed. This service and all associated backups/data will be permanently deleted from our servers and upstream providers. <strong>Please ensure you have downloaded all backups before proceeding.</strong>
            </p>
            <label style="display:flex;align-items:center;gap:var(--cv-space-2);margin-top:var(--cv-space-3);cursor:pointer;font-size:var(--cv-text-sm);">
                <input type="checkbox" id="confirm-delete" value="1">
                <span>I confirm that I have taken backups and understand my data will be lost forever.</span>
            </label>
        </div>

        <div class="cv-field" style="margin-bottom:var(--cv-space-4);">
            <label class="cv-label" for="reason">Reason for Cancellation</label>
            <textarea class="cv-input" id="reason" name="reason" rows="4" placeholder="Please let us know why you are cancelling..." style="width:100%;resize:vertical;"></textarea>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:var(--cv-space-4);">
            <a href="/client/services/<?= $id ?>" class="cv-btn cv-btn--secondary">Cancel & Go Back</a>
            <button class="cv-btn cv-btn--danger" type="submit" id="submit-btn">Confirm Cancellation</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('cancellation-form');
    const warningBox = document.getElementById('immediate-warning');
    const checkbox = document.getElementById('confirm-delete');
    const submitBtn = document.getElementById('submit-btn');
    const radios = document.getElementsByName('type');

    function toggleWarning() {
        let isImmediate = false;
        for (let i = 0; i < radios.length; i++) {
            if (radios[i].checked && radios[i].value === 'immediate') {
                isImmediate = true;
                break;
            }
        }

        if (isImmediate) {
            warningBox.style.display = 'block';
            checkbox.required = true;
        } else {
            warningBox.style.display = 'none';
            checkbox.required = false;
            checkbox.checked = false;
        }
    }

    for (let i = 0; i < radios.length; i++) {
        radios[i].addEventListener('change', toggleWarning);
    }

    form.addEventListener('submit', function(e) {
        let isImmediate = false;
        for (let i = 0; i < radios.length; i++) {
            if (radios[i].checked && radios[i].value === 'immediate') {
                isImmediate = true;
                break;
            }
        }

        if (isImmediate && !checkbox.checked) {
            e.preventDefault();
            alert('You must check the confirmation box to proceed with immediate cancellation.');
        }
    });

    toggleWarning();
});
</script>
