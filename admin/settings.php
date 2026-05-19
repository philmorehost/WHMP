<?php
require_once __DIR__ . '/../includes/Core.php';
use BAF\Core;

$core = Core::get_instance();
if (!$core->is_admin()) {
    header('Location: login');
    exit;
}

// Health Checks
$status = [
    'storage' => !empty($core->setting('google_drive_refresh_token')),
    'payments' => !empty($core->setting('plisio_api_key')),
    'auth' => !empty($core->setting('google_client_id')),
    'email' => true, // Placeholder
    'ads' => !empty($core->setting('adsense_pub_id')),
    'analytics' => !empty($core->setting('ga4_id'))
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Studio Settings — <?php echo Core::escape($core->setting('site_title', 'BUYAFROBEATS')); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="../assets/css/style.css">
    <meta name="csrf-token" content="<?php echo Core::csrf_token(); ?>">
    <style>
        :root { --sidebar-w: 220px; }
        .settings-layout { display: flex; gap: 40px; align-items: flex-start; max-width: 1000px; margin: 0 auto; padding: 40px 20px; }
        .settings-nav { width: var(--sidebar-w); position: sticky; top: 100px; flex-shrink: 0; }
        .settings-nav a { display: block; padding: 10px 16px; font-size: 13px; font-weight: 500; color: var(--ink-dim); text-decoration: none; border-radius: 8px; transition: all 0.2s; border-left: 2px solid transparent; }
        .settings-nav a:hover { color: var(--accent); background: var(--bg-2); }
        .settings-nav a.active { color: var(--accent); background: color-mix(in oklab, var(--accent) 8%, transparent); border-left-color: var(--accent); }
        .settings-nav .num { font-family: 'JetBrains Mono', monospace; font-size: 10px; margin-right: 8px; opacity: 0.5; }
        
        .settings-main { flex: 1; }
        .section { margin-bottom: 80px; scroll-margin-top: 120px; }
        .section h2 { font-size: 24px; font-weight: 700; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
        .section h2 .num { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--accent); border: 1px solid var(--accent); border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; }
        
        .status-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin-bottom: 32px; }
        .status-pill { padding: 12px; border-radius: 12px; background: var(--bg-2); border: 1px solid var(--line); display: flex; align-items: center; gap: 10px; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; }
        .status-dot.ok { background: var(--ok); box-shadow: 0 0 8px var(--ok); }
        .status-dot.err { background: var(--ink-mute); }
        .status-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

        .field { margin-bottom: 20px; position: relative; }
        .field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 8px; color: var(--ink-dim); }
        .field input, .field select, .field textarea { width: 100%; padding: 12px 16px; background: var(--bg-2); border: 1px solid var(--line); border-radius: 12px; font-size: 14px; color: var(--ink); transition: all 0.2s; }
        .field input:focus { border-color: var(--accent); outline: none; background: var(--bg-white); }
        .field .hint { font-size: 11px; color: var(--ink-mute); margin-top: 6px; line-height: 1.4; }
        
        .save-indicator { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; background: var(--ink); color: #fff; border-radius: 50px; font-size: 12px; font-weight: 600; display: none; align-items: center; gap: 10px; z-index: 10000; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .save-indicator.is-saving { display: flex; }
        .spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.2); border-top-color: #fff; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .write-only { position: relative; }
        .write-only input { padding-right: 80px; }
        .write-only .mask-btn { position: absolute; right: 12px; top: 32px; font-size: 10px; font-weight: 700; color: var(--accent); cursor: pointer; text-transform: uppercase; }

        /* Floating Save Button */
        .floating-save-wrap { position: fixed; right: 40px; top: 50%; transform: translateY(-50%); z-index: 9999; display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .floating-save { width: 64px; height: 64px; background: var(--ok); color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 30px rgba(0,0,0,0.2); cursor: pointer; transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); position: relative; }
        .floating-save:hover { transform: scale(1.1); box-shadow: 0 15px 40px rgba(0,0,0,0.3); }
        .floating-save.is-syncing { animation: saveGlow 1.5s infinite; pointer-events: none; opacity: 0.8; }
        .floating-save svg { width: 30px; height: 30px; }
        .save-label { font-family: 'JetBrains Mono', monospace; font-size: 10px; font-weight: 700; color: var(--ok); text-transform: uppercase; letter-spacing: 0.1em; opacity: 0; transform: translateY(10px); transition: all 0.3s ease; }
        .save-label.show { opacity: 1; transform: translateY(0); }

        @keyframes saveGlow {
            0% { box-shadow: 0 0 0 0 rgba(0, 200, 150, 0.4); }
            70% { box-shadow: 0 0 0 20px rgba(0, 200, 150, 0); }
            100% { box-shadow: 0 0 0 0 rgba(0, 200, 150, 0); }
        }

        /* Mobile Responsiveness */
        @media (max-width: 900px) {
            .settings-layout { flex-direction: column; padding: 20px 16px; gap: 20px; overflow-x: hidden; width: 100%; }
            .settings-nav { width: 100%; position: sticky; top: 60px; background: color-mix(in oklab, var(--bg) 95%, transparent); backdrop-filter: blur(10px); z-index: 90; overflow-x: auto; display: flex; padding: 8px 0; border-bottom: 1px solid var(--line); scrollbar-width: none; -ms-overflow-style: none; }
            .settings-nav::-webkit-scrollbar { display: none; }
            .settings-nav a { flex-shrink: 0; white-space: nowrap; border-left: none; border-bottom: 2px solid transparent; padding: 8px 12px; font-size: 12px; }
            .settings-nav a.active { border-left: none; border-bottom-color: var(--accent); background: transparent; }
            .floating-save-wrap { right: 16px; bottom: 20px; top: auto; transform: none; }
            .floating-save { width: 54px; height: 54px; }
            .floating-save svg { width: 22px; height: 22px; }
            .section { margin-bottom: 48px; }
            .section h2 { font-size: 20px; }
            .status-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .status-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="floating-save-wrap">
    <div id="save-all-btn" class="floating-save" title="Save All Changes">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
    </div>
    <div id="save-label" class="save-label">Saved</div>
</div>

<div class="topbar">
    <div class="topbar-inner">
        <a href="index" class="logo"><span class="dot"></span><?php echo $core->render_logo(); ?><span class="sub">/ studio</span></a>
        <div class="tabs">
            <a href="index" class="tab">Dashboard</a>
            <a href="upload" class="tab">+ Upload Beat</a>
            <a href="settings" class="tab is-active">Settings</a>
        </div>
        <div class="spacer"></div>
        <a href="logout" class="tab" style="font-size: 11px;">Logout</a>
    </div>
</div>

<div class="settings-layout">
    <nav class="settings-nav">
        <a href="#status" class="active"><span class="num">00</span> Status</a>
        <a href="#site"><span class="num">01</span> Site</a>
        <a href="#seo"><span class="num">02</span> SEO</a>
        <a href="#auth"><span class="num">03</span> Auth</a>
        <a href="#ads"><span class="num">04</span> Ads</a>
        <a href="#custom"><span class="num">05</span> Custom code</a>
        <a href="#storage"><span class="num">06</span> Storage</a>
        <a href="#auction"><span class="num">07</span> Auction</a>
        <a href="#license"><span class="num">08</span> License</a>
        <a href="#email"><span class="num">09</span> Email</a>
        <a href="#payments"><span class="num">10</span> Payments</a>
        <a href="#maintenance"><span class="num">11</span> Maintenance</a>
    </nav>

    <main class="settings-main">
        <!-- 00 Status -->
        <section id="status" class="section">
            <h2><span class="num">00</span> System Status</h2>
            <div class="status-grid">
                <div class="status-pill"><div class="status-dot <?php echo $status['storage'] ? 'ok' : 'err'; ?>"></div><span class="status-label">Google Drive</span></div>
                <div class="status-pill"><div class="status-dot <?php echo $status['payments'] ? 'ok' : 'err'; ?>"></div><span class="status-label">Plisio</span></div>
                <div class="status-pill"><div class="status-dot <?php echo $status['auth'] ? 'ok' : 'err'; ?>"></div><span class="status-label">OAuth</span></div>
                <div class="status-pill"><div class="status-dot <?php echo $status['email'] ? 'ok' : 'err'; ?>"></div><span class="status-label">Email</span></div>
                <div class="status-pill"><div class="status-dot <?php echo $status['ads'] ? 'ok' : 'err'; ?>"></div><span class="status-label">AdSense</span></div>
                <div class="status-pill"><div class="status-dot <?php echo $status['analytics'] ? 'ok' : 'err'; ?>"></div><span class="status-label">GA4</span></div>
            </div>
        </section>

        <!-- 01 Site -->
        <section id="site" class="section">
            <h2><span class="num">01</span> Site</h2>
            <div class="field">
                <label>Site Title</label>
                <input type="text" data-key="site_title" value="<?php echo Core::escape($core->setting('site_title')); ?>">
            </div>
            <div class="field">
                <label>Site Tagline</label>
                <input type="text" data-key="site_tagline" value="<?php echo Core::escape($core->setting('site_tagline')); ?>">
            </div>
            <div class="field">
                <label>Contact Email</label>
                <input type="email" data-key="contact_email" value="<?php echo Core::escape($core->setting('contact_email')); ?>">
            </div>
        </section>

        <!-- 02 SEO -->
        <section id="seo" class="section">
            <h2><span class="num">02</span> SEO</h2>
            <div class="field">
                <label>Meta Description</label>
                <textarea data-key="meta_description"><?php echo Core::escape($core->setting('meta_description')); ?></textarea>
            </div>
            <div class="field">
                <label>GA4 Measurement ID</label>
                <input type="text" data-key="ga4_id" value="<?php echo Core::escape($core->setting('ga4_id')); ?>" placeholder="G-XXXXXXXX">
            </div>
        </section>

        <!-- 03 Auth -->
        <section id="auth" class="section">
            <h2><span class="num">03</span> Auth</h2>
            <div class="field">
                <label>Google Client ID</label>
                <input type="text" data-key="google_client_id" value="<?php echo Core::escape($core->setting('google_client_id')); ?>">
            </div>
            <div class="field">
                <label>Require Email Verification</label>
                <select data-key="require_email_verify">
                    <option value="1" <?php echo $core->setting('require_email_verify') == '1' ? 'selected' : ''; ?>>Yes</option>
                    <option value="0" <?php echo $core->setting('require_email_verify') == '0' ? 'selected' : ''; ?>>No</option>
                </select>
            </div>
        </section>

        <!-- 04 Ads -->
        <section id="ads" class="section">
            <h2><span class="num">04</span> Ads</h2>
            <div class="field">
                <label>AdSense Publisher ID</label>
                <input type="text" data-key="adsense_pub_id" value="<?php echo Core::escape($core->setting('adsense_pub_id')); ?>" placeholder="pub-xxxxxxxxxxxxxx">
            </div>
            <div class="field">
                <label>ads.txt Content</label>
                <textarea data-key="ads_txt" style="height: 100px; font-family: monospace;"><?php echo Core::escape($core->setting('ads_txt')); ?></textarea>
            </div>
        </section>

        <!-- 05 Custom code -->
        <section id="custom" class="section">
            <h2><span class="num">05</span> Custom Code</h2>
            <div class="field">
                <label>Header HTML (before &lt;/head&gt;)</label>
                <textarea data-key="custom_header" style="height: 100px; font-family: monospace;"><?php echo Core::escape($core->setting('custom_header')); ?></textarea>
            </div>
            <div class="field">
                <label>Footer HTML (before &lt;/body&gt;)</label>
                <textarea data-key="custom_footer" style="height: 100px; font-family: monospace;"><?php echo Core::escape($core->setting('custom_footer')); ?></textarea>
            </div>
            <div class="field">
                <label>Custom CSS</label>
                <textarea data-key="custom_css" style="height: 100px; font-family: monospace;"><?php echo Core::escape($core->setting('custom_css')); ?></textarea>
            </div>
        </section>

        <!-- 06 Storage -->
        <section id="storage" class="section">
            <h2><span class="num">06</span> Storage</h2>
            <?php 
            $has_creds = !empty($core->setting('google_drive_client_id')) && !empty($core->setting('google_drive_client_secret'));
            $has_token = !empty($core->setting('google_drive_refresh_token'));
            ?>
            <div style="background: var(--bg-2); padding: 24px; border-radius: 16px; margin-bottom: 24px; border: 1px solid var(--line);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                    <div>
                        <div style="font-weight: 700; font-size: 16px; margin-bottom: 4px;"><?php echo $has_token ? 'Google Drive Connected' : 'Google Drive Not Connected'; ?></div>
                        <div style="font-size: 12px; color: var(--ink-dim);">Authenticate with Google to enable cloud uploads and file selection.</div>
                    </div>
                    <?php if ($has_creds): ?>
                        <a href="../api/google_drive_auth" class="btn btn-primary" style="font-size: 12px; padding: 10px 20px;">Connect →</a>
                    <?php endif; ?>
                </div>

                <!-- Storage Help Wizard -->
                <div style="background: color-mix(in oklab, var(--accent) 5%, var(--bg-2)); border: 1px solid var(--line); border-radius: 12px; padding: 20px;">
                    <h4 style="margin: 0 0 16px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--accent);">Step-by-Step Setup Guide</h4>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div style="font-size: 12px; line-height: 1.6; color: var(--ink-dim);">
                            <p style="margin-bottom: 12px;"><b style="color: var(--ink);">1. Create Project:</b><br>Visit <a href="https://console.cloud.google.com/" target="_blank" style="color:var(--accent)">Cloud Console</a>, create a project, and enable <b>Google Drive API</b>.</p>
                            <p style="margin-bottom: 12px;"><b style="color: var(--ink);">2. OAuth Screen:</b><br>Set user type to <b>External</b> and add your developer email.</p>
                            <p><b style="color: var(--ink);">3. Create ID:</b><br>Go to <b>Credentials > Create > OAuth Client ID</b> (Web App).</p>
                        </div>
                        <div style="font-size: 12px; line-height: 1.6; color: var(--ink-dim);">
                            <p style="margin-bottom: 12px;"><b style="color: var(--ink);">4. Redirect URI:</b><br>Copy this into Google Console:<br>
                                <code style="display:block; background:var(--bg); padding:8px; border-radius:6px; margin:6px 0; color:var(--ink); border: 1px solid var(--line); font-family: 'JetBrains Mono'; font-size: 10px;"><?php echo $core->get_site_url(); ?>/api/google_drive_callback</code>
                            </p>
                            <p><b style="color: var(--ink);">5. Save & Connect:</b><br>Paste Client ID/Secret below, click <b>floating Save</b>, then click <b>Connect</b> above.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="field write-only">
                <label>Drive Client ID</label>
                <input type="text" data-key="google_drive_client_id" value="<?php echo Core::escape($core->setting('google_drive_client_id')); ?>">
            </div>
            <div class="field write-only">
                <label>Drive Client Secret</label>
                <input type="password" data-key="google_drive_client_secret" value="<?php echo Core::escape($core->setting('google_drive_client_secret')); ?>">
            </div>
            <div class="field">
                <label>Refresh Token (Auto-filled)</label>
                <input type="text" readonly value="<?php echo $has_token ? '••••••••••••••••••••••••' : ''; ?>" style="background: var(--bg-3);">
            </div>
        </section>

        <!-- 07 Auction -->
        <section id="auction" class="section">
            <h2><span class="num">07</span> Auction</h2>
            <div class="field">
                <label>Default Duration (Minutes)</label>
                <input type="number" data-key="auction_duration" value="<?php echo Core::escape($core->setting('auction_duration', '30')); ?>">
            </div>
            <div class="field">
                <label>Anti-Snipe Window (Minutes)</label>
                <input type="number" data-key="auction_anti_snipe" value="<?php echo Core::escape($core->setting('auction_anti_snipe', '2')); ?>">
            </div>
            <div class="field">
                <label>Minimum Bid Increment ($)</label>
                <input type="number" data-key="auction_min_inc" value="<?php echo Core::escape($core->setting('auction_min_inc', '5')); ?>">
            </div>
        </section>

        <!-- 08 License -->
        <section id="license" class="section">
            <h2><span class="num">08</span> License</h2>
            <div class="field">
                <label>Required Credit Phrase</label>
                <input type="text" data-key="license_credit" value="<?php echo Core::escape($core->setting('license_credit', 'Produced by OBV')); ?>">
                <div class="hint">The verbatim text the buyer must include in their release metadata.</div>
            </div>
            <div class="field">
                <label>Download Window (Days)</label>
                <input type="number" data-key="license_window" value="<?php echo Core::escape($core->setting('license_window', '7')); ?>">
            </div>
        </section>

        <!-- 09 Email -->
        <section id="email" class="section">
            <h2><span class="num">09</span> Email</h2>
            <div class="field">
                <label>Sender Name</label>
                <input type="text" data-key="email_sender_name" value="<?php echo Core::escape($core->setting('email_sender_name', 'BEATZAZA')); ?>">
            </div>
            <div class="field">
                <label>From Address</label>
                <input type="email" data-key="email_from" value="<?php echo Core::escape($core->setting('email_from')); ?>">
            </div>
        </section>

        <!-- 10 Payments -->
        <section id="payments" class="section">
            <h2><span class="num">10</span> Payments</h2>
            <div class="field write-only">
                <label>Plisio API Key</label>
                <input type="password" data-key="plisio_api_key" value="<?php echo Core::escape($core->setting('plisio_api_key')); ?>">
            </div>
            <div class="field">
                <label>Payment Window (Hours)</label>
                <input type="number" data-key="payment_window" value="<?php echo Core::escape($core->setting('payment_window', '24')); ?>">
                <div class="hint">Time the winner has to pay before the cascade advances.</div>
            </div>
            <div class="field">
                <label>Required Confirmations</label>
                <input type="number" data-key="plisio_confirmations" value="<?php echo Core::escape($core->setting('plisio_confirmations', '2')); ?>">
            </div>
        </section>

        <!-- 11 Maintenance -->
        <section id="maintenance" class="section">
            <h2><span class="num">11</span> Maintenance</h2>
            <div style="background: var(--bg-2); padding: 24px; border-radius: 16px; border: 1px solid var(--line);">
                <h4 style="margin: 0 0 12px; font-size: 14px; font-weight: 700;">Cron Job Setup</h4>
                <p style="font-size: 12px; color: var(--ink-dim); line-height: 1.6; margin-bottom: 16px;">
                    To ensure auctions end on time and cascade payments work automatically, you must set up a cron job on your server to run every minute.
                </p>
                
                <div class="field">
                    <label>Recommended Cron Command</label>
                    <div style="position: relative;">
                        <code style="display:block; background:var(--bg); padding:16px; border-radius:12px; color:var(--ink); border: 1px solid var(--line); font-family: 'JetBrains Mono'; font-size: 11px; white-space: pre-wrap;">* * * * * php <?php echo realpath(__DIR__ . '/../api/cron.php'); ?> > /dev/null 2>&1</code>
                    </div>
                </div>

                <div style="background: color-mix(in oklab, var(--accent) 5%, var(--bg-2)); border: 1px solid var(--line); border-radius: 12px; padding: 16px; font-size: 11px; color: var(--ink-dim); line-height: 1.5;">
                    <b style="color: var(--ink);">Note:</b> Depending on your hosting (cPanel, Plesk, etc.), you might need to use the full path to the PHP binary (e.g., <code>/usr/local/bin/php</code>). If you cannot set up a system cron, the system will still process auctions whenever the dashboard is visited.
                </div>
            </div>
        </section>
    </main>
</div>

<div id="save-indicator" class="save-indicator">
    <div class="spinner"></div>
    <span>Saving changes...</span>
</div>

<script>
    // AJAX Auto-save
    const inputs = document.querySelectorAll('[data-key]');
    const indicator = document.getElementById('save-indicator');
    let saveTimeout;

    inputs.forEach(input => {
        input.addEventListener('input', () => {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(() => save(input), 800);
        });
    });

    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    async function save(el) {
        indicator.classList.add('is-saving');
        const key = el.getAttribute('data-key');
        const value = el.value;

        try {
            const fd = new FormData();
            fd.append('key', key);
            fd.append('value', value);
            fd.append('csrf_token', csrfToken);

            const res = await fetch('api/save_setting', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            
            if (data.success) {
                el.style.borderColor = 'var(--ok)';
                setTimeout(() => el.style.borderColor = '', 1000);
            } else {
                showToast('Error: ' + (data.error || 'Failed to save'));
                el.style.borderColor = 'var(--ink-mute)';
            }
        } catch (err) {
            console.error(err);
            showToast('Connection error');
        } finally {
            indicator.classList.remove('is-saving');
        }
    }

    // Batch Save
    const saveBtn = document.getElementById('save-all-btn');
    const saveLabel = document.getElementById('save-label');

    saveBtn.addEventListener('click', async () => {
        saveBtn.classList.add('is-syncing');
        saveLabel.classList.remove('show');

        const settings = {};
        inputs.forEach(input => {
            settings[input.getAttribute('data-key')] = input.value;
        });

        try {
            const fd = new FormData();
            fd.append('settings', JSON.stringify(settings));
            fd.append('csrf_token', csrfToken);

            const res = await fetch('api/save_setting', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();

            if (data.success) {
                saveLabel.classList.add('show');
                setTimeout(() => saveLabel.classList.remove('show'), 2500);
            } else {
                alert('Error: ' + (data.error || 'Failed to save settings'));
            }
        } catch (err) {
            console.error(err);
            alert('Failed to save some settings. Please check your connection.');
        } finally {
            saveBtn.classList.remove('is-syncing');
        }
    });

    function showToast(msg) {
        const toast = document.createElement('div');
        toast.className = 'toast';
        toast.style.cssText = 'position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:var(--ink); color:#fff; padding:12px 24px; border-radius:50px; font-size:12px; font-weight:600; z-index:99999; animation: slideUp 0.3s ease-out;';
        toast.innerText = msg;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
</script>
<style>
@keyframes slideUp { from { bottom: -50px; opacity: 0; } to { bottom: 20px; opacity: 1; } }
</style>

</body>
</html>
