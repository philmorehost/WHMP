<?php
/** @var array<string, mixed> $client */
/** @var int $servicesCount */
/** @var int $domainsCount */
/** @var int $invoicesCount */
/** @var int $ticketsCount */
/** @var array<int, array<string, mixed>> $unpaidInvoices */
/** @var array<int, array<string, mixed>> $activeServices */
?>
<div class="cv-hero" style="background:var(--cv-bg-surface);padding:var(--cv-space-6);border-radius:var(--cv-radius-md);margin-bottom:var(--cv-space-4);border:1px solid var(--cv-border-default);">
    <h1 style="font-size:var(--cv-text-2xl);font-family:'Hanken Grotesk', sans-serif;margin:0;color:var(--cv-text-primary);">Hello, <?= e($client['first_name']) ?>!</h1>
    <p style="color:var(--cv-text-secondary);margin:var(--cv-space-1) 0 0 0;font-size:var(--cv-text-sm);">Welcome back to your client area dashboard. From here you can manage your hosting packages, domains, invoices, and support requests.</p>
</div>

<!-- Lagom2 Summary Stats Cards Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-4);margin-bottom:var(--cv-space-6);">
    <a href="/client/services" style="text-decoration:none;color:inherit;">
        <div class="cv-card" style="padding:var(--cv-space-4);text-align:center;transition:transform var(--cv-transition-fast), border-color var(--cv-transition-fast);cursor:pointer;border:1px solid var(--cv-border-default);" onmouseover="this.style.transform='translateY(-3px)';this.style.borderColor='var(--cv-color-brand-500)';" onmouseout="this.style.transform='none';this.style.borderColor='var(--cv-border-default)';">
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);text-transform:uppercase;font-weight:600;letter-spacing:0.05em;">Services</div>
            <div style="font-size:var(--cv-text-3xl);font-family:'Hanken Grotesk', sans-serif;font-weight:800;color:var(--cv-color-brand-500);margin:var(--cv-space-2) 0;"><?= $servicesCount ?></div>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">Active Services</div>
        </div>
    </a>
    <a href="/client/domains" style="text-decoration:none;color:inherit;">
        <div class="cv-card" style="padding:var(--cv-space-4);text-align:center;transition:transform var(--cv-transition-fast), border-color var(--cv-transition-fast);cursor:pointer;border:1px solid var(--cv-border-default);" onmouseover="this.style.transform='translateY(-3px)';this.style.borderColor='var(--cv-color-brand-500)';" onmouseout="this.style.transform='none';this.style.borderColor='var(--cv-border-default)';">
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);text-transform:uppercase;font-weight:600;letter-spacing:0.05em;">Domains</div>
            <div style="font-size:var(--cv-text-3xl);font-family:'Hanken Grotesk', sans-serif;font-weight:800;color:var(--cv-color-brand-500);margin:var(--cv-space-2) 0;"><?= $domainsCount ?></div>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">Active Domains</div>
        </div>
    </a>
    <a href="/client/invoices" style="text-decoration:none;color:inherit;">
        <div class="cv-card" style="padding:var(--cv-space-4);text-align:center;transition:transform var(--cv-transition-fast), border-color var(--cv-transition-fast);cursor:pointer;border:1px solid var(--cv-border-default);" onmouseover="this.style.transform='translateY(-3px)';this.style.borderColor='var(--cv-color-brand-500)';" onmouseout="this.style.transform='none';this.style.borderColor='var(--cv-border-default)';">
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);text-transform:uppercase;font-weight:600;letter-spacing:0.05em;">Invoices</div>
            <div style="font-size:var(--cv-text-3xl);font-family:'Hanken Grotesk', sans-serif;font-weight:800;color:<?= $invoicesCount > 0 ? 'var(--cv-color-danger, #ef4444)' : 'var(--cv-text-primary)' ?>;margin:var(--cv-space-2) 0;"><?= $invoicesCount ?></div>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);"><?= $invoicesCount > 0 ? 'Unpaid Invoices' : 'All Invoices Paid' ?></div>
        </div>
    </a>
    <a href="/client/tickets" style="text-decoration:none;color:inherit;">
        <div class="cv-card" style="padding:var(--cv-space-4);text-align:center;transition:transform var(--cv-transition-fast), border-color var(--cv-transition-fast);cursor:pointer;border:1px solid var(--cv-border-default);" onmouseover="this.style.transform='translateY(-3px)';this.style.borderColor='var(--cv-color-brand-500)';" onmouseout="this.style.transform='none';this.style.borderColor='var(--cv-border-default)';">
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);text-transform:uppercase;font-weight:600;letter-spacing:0.05em;">Tickets</div>
            <div style="font-size:var(--cv-text-3xl);font-family:'Hanken Grotesk', sans-serif;font-weight:800;color:var(--cv-color-brand-500);margin:var(--cv-space-2) 0;"><?= $ticketsCount ?></div>
            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">Open Tickets</div>
        </div>
    </a>
</div>

<!-- Domain Registration Search Bar -->
<div class="cv-card" style="margin-bottom:var(--cv-space-6);border:1px solid var(--cv-border-default);background:var(--cv-bg-surface-sunken);">
    <h3 style="margin:0 0 var(--cv-space-2) 0;font-size:var(--cv-text-md);font-family:'Hanken Grotesk', sans-serif;color:var(--cv-text-primary);">Find Your New Domain Name</h3>
    <form method="get" action="/store" style="display:flex;gap:var(--cv-space-2);">
        <input class="cv-input" name="domain_search" placeholder="Enter the domain you want to register (e.g. mywebsite.com)" style="flex:1;">
        <button class="cv-btn" type="submit">Search</button>
    </form>
</div>

<div class="cv-dashboard-grid--2-3">
    <!-- Left Column: Unpaid Invoices & Active Services -->
    <div style="display:flex;flex-direction:column;gap:var(--cv-space-6);">
        <!-- Unpaid Invoices -->
        <div class="cv-card" style="border:1px solid var(--cv-border-default);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--cv-space-3);">
                <h3 style="margin:0;font-family:'Hanken Grotesk', sans-serif;font-size:var(--cv-text-md);">Unpaid Invoices</h3>
                <a href="/client/invoices" style="font-size:var(--cv-text-xs);color:var(--cv-color-brand-500);font-weight:600;text-decoration:none;">View All Invoices &rarr;</a>
            </div>
            
            <?php if ($unpaidInvoices !== []): ?>
                <table class="cv-table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Due Date</th>
                            <th>Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unpaidInvoices as $invoice): ?>
                            <tr>
                                <td><strong>#<?= e($invoice['invoice_number']) ?></strong></td>
                                <td><?= e($invoice['due_date']) ?></td>
                                <td>$<?= number_format((float) $invoice['total'], 2) ?></td>
                                <td>
                                    <a class="cv-btn cv-btn--secondary" href="/client/invoices/<?= (int) $invoice['id'] ?>" style="padding:var(--cv-space-1) var(--cv-space-2);font-size:var(--cv-text-xs);background:var(--cv-color-brand-500);color:#ffffff;border:none;">Pay Now</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align:center;padding:var(--cv-space-6);color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                    You do not have any unpaid invoices. Thank you!
                </div>
            <?php endif; ?>
        </div>

        <!-- Active Services -->
        <div class="cv-card" style="border:1px solid var(--cv-border-default);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--cv-space-3);">
                <h3 style="margin:0;font-family:'Hanken Grotesk', sans-serif;font-size:var(--cv-text-md);">Your Active Products & Services</h3>
                <a href="/client/services" style="font-size:var(--cv-text-xs);color:var(--cv-color-brand-500);font-weight:600;text-decoration:none;">View All Services &rarr;</a>
            </div>

            <?php if ($activeServices !== []): ?>
                <table class="cv-table">
                    <thead>
                        <tr>
                            <th>Product/Domain</th>
                            <th>Billing Cycle</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeServices as $service): ?>
                            <tr>
                                <td>
                                    <strong><?= e($service['product_name']) ?></strong><br>
                                    <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);"><?= e($service['product_name']) ?></span>
                                </td>
                                <td><span class="cv-badge cv-badge--neutral"><?= e($service['billing_cycle']) ?></span></td>
                                <td>$<?= number_format((float) $service['amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align:center;padding:var(--cv-space-6);color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                    You do not have any active products or services. <a href="/store" style="color:var(--cv-color-brand-500);text-decoration:none;font-weight:600;">Browse Store &rarr;</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right Column: Quick Links / Actions -->
    <div style="display:flex;flex-direction:column;gap:var(--cv-space-6);">
        <!-- Quick Actions Card -->
        <div class="cv-card" style="border:1px solid var(--cv-border-default);">
            <h3 style="margin:0 0 var(--cv-space-3) 0;font-family:'Hanken Grotesk', sans-serif;font-size:var(--cv-text-md);">Quick Actions</h3>
            <div style="display:flex;flex-direction:column;gap:var(--cv-space-2);">
                <a href="/store" class="cv-btn cv-btn--secondary" style="text-align:left;display:block;width:100%;text-decoration:none;">🛍️ Order New Services</a>
                <a href="/store" class="cv-btn cv-btn--secondary" style="text-align:left;display:block;width:100%;text-decoration:none;">🌐 Register New Domains</a>
                <a href="/client/tickets" class="cv-btn cv-btn--secondary" style="text-align:left;display:block;width:100%;text-decoration:none;">✉️ Submit Support Ticket</a>
                <a href="/client/account" class="cv-btn cv-btn--secondary" style="text-align:left;display:block;width:100%;text-decoration:none;">👤 Edit Profile Details</a>
                <a href="/client/affiliate" class="cv-btn cv-btn--secondary" style="text-align:left;display:block;width:100%;text-decoration:none;">🤝 Affiliate Program</a>
                <form method="post" action="/client/logout" style="width:100%;margin:0;"><?= csrf_field() ?>
                    <button class="cv-btn cv-btn--secondary" type="submit" style="text-align:left;width:100%;background:rgba(239, 68, 68, 0.1);color:#ef4444;border-color:rgba(239, 68, 68, 0.2);">🚪 Log Out</button>
                </form>
            </div>
        </div>

        <!-- System Information Card -->
        <div class="cv-card" style="border:1px solid var(--cv-border-default);">
            <h3 style="margin:0 0 var(--cv-space-2) 0;font-family:'Hanken Grotesk', sans-serif;font-size:var(--cv-text-md);">Support Options</h3>
            <p style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);margin:0 0 var(--cv-space-3) 0;">Find answers instantly in our knowledgebase or download tools and resources.</p>
            <div style="display:flex;gap:var(--cv-space-2);">
                <a href="/kb" class="cv-btn cv-btn--secondary" style="flex:1;text-align:center;text-decoration:none;font-size:var(--cv-text-xs);">📚 KB articles</a>
                <a href="/downloads" class="cv-btn cv-btn--secondary" style="flex:1;text-align:center;text-decoration:none;font-size:var(--cv-text-xs);">💾 Downloads</a>
            </div>
        </div>
    </div>
</div>
