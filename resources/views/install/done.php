<div class="cv-card" style="text-align: center; padding: var(--cv-space-8) var(--cv-space-6);">
    <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(74, 222, 128, 0.1); border: 1px solid rgba(74, 222, 128, 0.2); display: flex; align-items: center; justify-content: center; margin: 0 auto var(--cv-space-4) auto;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#4ade80" style="width: 32px; height: 32px;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
    </div>
    
    <h1 class="cv-card__title">Installation Complete!</h1>
    <span class="cv-badge cv-badge--success" style="margin-bottom: var(--cv-space-4);">Lock File Written Successfully</span>
    
    <p style="color:var(--cv-text-secondary); max-width: 32rem; margin: 0 auto var(--cv-space-6) auto;">
        Your system has been successfully configured. The installer has written the <code>.installed.lock</code> file to secure the application.
    </p>

    <div style="text-align: left; background: var(--cv-bg-surface-sunken); border: 1px solid var(--cv-border-default); border-radius: var(--cv-radius-md); padding: var(--cv-space-4); margin-bottom: var(--cv-space-6);">
        <h3 style="margin-top: 0; color: #ffffff; font-family: 'Hanken Grotesk', sans-serif;">Required Next Steps:</h3>
        <ul style="color:var(--cv-text-secondary); padding-left: var(--cv-space-4); margin-bottom: 0; line-height: 1.6;">
            <li style="margin-bottom: var(--cv-space-2);">Log in using your administrator credentials.</li>
            <li style="margin-bottom: var(--cv-space-2);">Configure payment gateways in **Billing &rarr; Payment Gateways**.</li>
            <li style="margin-bottom: var(--cv-space-2);">Link hosting servers and domain registrars.</li>
            <li style="margin-bottom: var(--cv-space-2);">Verify system settings (currencies, tax rules, templates).</li>
            <li>Configure a system cron job to point to <code>bin/cron.php</code> to automate invoicing, renewals, and operations.</li>
        </ul>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--cv-space-4);">
        <a class="cv-btn" href="/login">Admin Area Login</a>
        <a class="cv-btn" href="/" style="background: var(--cv-bg-surface-raised); color: #ffffff; border: 1px solid var(--cv-border-default);">Visit Homepage</a>
    </div>
</div>
