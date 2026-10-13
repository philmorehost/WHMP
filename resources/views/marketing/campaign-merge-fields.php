<section aria-label="Available campaign merge fields" style="border:1px solid var(--cv-border-default);border-radius:10px;padding:var(--cv-space-3);margin:var(--cv-space-3) 0;background:var(--cv-bg-surface-sunken);">
    <h3 style="margin:0 0 6px;">Personalize each recipient</h3>
    <p style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin:0 0 var(--cv-space-2);">
        Use these in the subject or body. Bracket labels and <code>{{snake_case}}</code> tags are replaced separately for each recipient when the campaign is sent. Plain text and HTML bodies are both supported.
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:var(--cv-space-3);font-size:var(--cv-text-sm);">
        <div>
            <strong>Name and contact</strong>
            <ul style="margin:6px 0 0;padding-left:20px;">
                <li>Full name: <code>[Client Name]</code> or <code>{{client_name}}</code></li>
                <li>First name: <code>[First Name]</code> or <code>{{first_name}}</code></li>
                <li>Last name: <code>[Last Name]</code> or <code>{{last_name}}</code></li>
                <li>Email: <code>[Email]</code> or <code>{{email}}</code>; also <code>[Client Email]</code> or <code>{{client_email}}</code></li>
                <li>Email domain (after @): <code>[Email Domain]</code> or <code>{{email_domain}}</code></li>
            </ul>
        </div>
        <div>
            <strong>Client account</strong>
            <ul style="margin:6px 0 0;padding-left:20px;">
                <li>Primary active domain: <code>[Domain]</code> or <code>{{domain}}</code>; also <code>[Client Domain]</code> or <code>{{client_domain}}</code></li>
                <li>Client ID: <code>[Client ID]</code> or <code>{{client_id}}</code></li>
                <li>Client's company: <code>[Client Company]</code> or <code>{{client_company}}</code></li>
                <li>Phone: <code>[Phone]</code> or <code>{{phone}}</code>; city: <code>[City]</code> or <code>{{city}}</code>; state: <code>[State]</code> or <code>{{state}}</code>; postcode: <code>[Postcode]</code> or <code>{{postcode}}</code>; country: <code>[Country]</code> or <code>{{country}}</code></li>
            </ul>
        </div>
        <div>
            <strong>Billing website and links</strong>
            <ul style="margin:6px 0 0;padding-left:20px;">
                <li>Configured website brand: <code>[Company Name]</code> or <code>{{company_name}}</code></li>
                <li>Billing website address: <code>[Billing Website]</code> or <code>{{billing_website}}</code></li>
                <li>Client invoices page: <code>[Billing URL]</code> or <code>{{billing_url}}</code></li>
                <li>Client area: <code>[Client Area URL]</code> or <code>{{client_area_url}}</code></li>
                <li>Support tickets: <code>[Support URL]</code> or <code>{{support_url}}</code></li>
            </ul>
        </div>
    </div>
    <p style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);margin:var(--cv-space-2) 0 0;">
        The domain is the newest active hosting-service domain, falling back to an active registered domain. Missing account details are blank; for external addresses, the name falls back to “there”. Other accepted aliases include <code>{{email_address}}</code>, <code>{{domain_name}}</code>, <code>{{account_id}}</code>, <code>{{billing_company_name}}</code>, <code>{{client_company_name}}</code>, <code>{{client_phone}}</code>, <code>{{client_city}}</code>, <code>{{region}}</code>, <code>{{postal_code}}</code>/<code>{{zip}}</code>, <code>{{client_country}}</code>, <code>{{billing_website_url}}</code>/<code>{{website_url}}</code>/<code>{{site_url}}</code>/<code>{{app_url}}</code>, <code>{{billing_portal_url}}</code>/<code>{{invoice_url}}</code>, <code>{{client_portal_url}}</code> and <code>{{tickets_url}}</code>.
    </p>
</section>
