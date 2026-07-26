<?php
/** @var array<int, array{name: string, display_name: string, frequency_minutes: int, enabled: bool, time_of_day: string}> $jobs */
?>

<div class="cv-card">
    <div class="cv-card__header">
        <h1 class="cv-card__title">Automation Settings</h1>
        <p class="cv-text-secondary">Configure when automated jobs run and enable/disable them</p>
    </div>

    <form method="POST" action="/admin/system/automation" class="cv-form">
        <?php if (isset($_GET['saved'])): ?>
            <div class="cv-alert cv-alert--success">
                Automation settings saved successfully.
            </div>
        <?php endif; ?>

        <div class="cv-form__section">
            <h2 class="cv-form__section-title">Job Configuration</h2>

            <table class="cv-table cv-table--striped">
                <thead>
                    <tr>
                        <th>Job Name</th>
                        <th>Frequency</th>
                        <th>Enabled</th>
                        <th>Time of Day</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($job['display_name']) ?></strong>
                                <br>
                                <small class="cv-text-secondary"><?= htmlspecialchars($job['name']) ?></small>
                            </td>
                            <td>
                                <?php if ($job['frequency_minutes'] === 1440): ?>
                                    Daily (every 24 hours)
                                <?php else: ?>
                                    Every <?= $job['frequency_minutes'] ?> minutes
                                <?php endif; ?>
                            </td>
                            <td>
                                <label class="cv-form__checkbox">
                                    <input
                                        type="checkbox"
                                        name="enabled_<?= htmlspecialchars($job['name']) ?>"
                                        <?= $job['enabled'] ? 'checked' : '' ?>
                                    >
                                    <span class="cv-form__checkbox-label">Enabled</span>
                                </label>
                            </td>
                            <td>
                                <?php if ($job['frequency_minutes'] === 1440): ?>
                                    <input
                                        type="time"
                                        name="time_of_day_<?= htmlspecialchars($job['name']) ?>"
                                        value="<?= htmlspecialchars($job['time_of_day']) ?>"
                                        class="cv-form__input"
                                    >
                                <?php else: ?>
                                    <span class="cv-text-secondary">N/A</span>
                                    <input type="hidden" name="time_of_day_<?= htmlspecialchars($job['name']) ?>" value="00:00">
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="cv-form__actions">
            <button type="submit" class="cv-btn cv-btn--primary">
                Save Settings
            </button>
            <a href="/admin" class="cv-btn cv-btn--secondary">
                Cancel
            </a>
        </div>
    </form>

    <div class="cv-card__section" style="margin-top: 40px; border-top: 1px solid #e0e0e0; padding-top: 20px;">
        <h2 class="cv-card__subtitle">Last Run Times</h2>
        <p class="cv-text-secondary">Run <code>php bin/cron.php</code> manually to test jobs. Last run times are tracked in <code>storage/cache/cron-state.json</code></p>
        <p class="cv-text-secondary" style="margin-top: 10px;">
            <strong>Tip:</strong> Set the OS cron entry to run every minute:<br>
            <code>* * * * * php /path/to/bin/cron.php >> /path/to/storage/cron.log 2>&1</code>
        </p>
    </div>
</div>

<style>
    .cv-table {
        width: 100%;
        border-collapse: collapse;
    }

    .cv-table thead {
        background-color: #f5f5f5;
    }

    .cv-table th,
    .cv-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #e0e0e0;
    }

    .cv-table--striped tbody tr:nth-child(odd) {
        background-color: #fafafa;
    }

    .cv-form__checkbox {
        display: flex;
        align-items: center;
        cursor: pointer;
    }

    .cv-form__checkbox input {
        margin-right: 8px;
    }

    .cv-alert {
        padding: 12px 16px;
        border-radius: 4px;
        margin-bottom: 20px;
    }

    .cv-alert--success {
        background-color: #e8f5e9;
        border-left: 4px solid #4caf50;
        color: #2e7d32;
    }
</style>
