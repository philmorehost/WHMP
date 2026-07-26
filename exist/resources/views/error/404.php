<?php
/** @var array<array{id: int, name: string}> $categories */
/** @var string $requestPath */
?>

<div style="min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 40px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">

    <div style="max-width: 800px; width: 100%; background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden;">

        <!-- Header Section -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 60px 40px; text-align: center;">
            <div style="font-size: 120px; font-weight: bold; line-height: 1; margin-bottom: 20px; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                404
            </div>
            <h1 style="margin: 0 0 10px 0; font-size: 32px; font-weight: 600;">
                Page Not Found
            </h1>
            <p style="margin: 0; font-size: 16px; opacity: 0.9;">
                We couldn't find what you're looking for.
            </p>
        </div>

        <!-- Content Section -->
        <div style="padding: 40px;">

            <!-- Search Suggestion -->
            <div style="margin-bottom: 40px; text-align: center;">
                <p style="margin: 0 0 20px 0; color: #666; font-size: 14px;">
                    The page <code style="background: #f5f5f5; padding: 4px 8px; border-radius: 4px; color: #e74c3c;"><?= htmlspecialchars($requestPath) ?></code> doesn't exist.
                </p>

                <a href="/" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s; margin-bottom: 30px;">
                    ← Back to Home
                </a>
            </div>

            <!-- Categories Section -->
            <?php if (!empty($categories)): ?>
                <div style="border-top: 1px solid #e0e0e0; padding-top: 40px;">
                    <h2 style="margin: 0 0 30px 0; font-size: 20px; font-weight: 600; color: #333;">
                        Browse Our Services & Products
                    </h2>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <?php foreach ($categories as $category): ?>
                            <a href="/catalog/group/<?= htmlspecialchars($category['id']) ?>" style="display: block; padding: 20px; background: #f9f9f9; border: 2px solid #e0e0e0; border-radius: 8px; text-decoration: none; transition: all 0.3s; color: #333;">
                                <div style="font-weight: 600; font-size: 16px; margin-bottom: 8px; color: #667eea;">
                                    <?= htmlspecialchars($category['name']) ?>
                                </div>
                                <div style="font-size: 13px; color: #999;">
                                    View Products →
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Help Links Section -->
            <div style="margin-top: 40px; border-top: 1px solid #e0e0e0; padding-top: 30px;">
                <h3 style="margin: 0 0 20px 0; font-size: 16px; font-weight: 600; color: #333;">
                    Need Help?
                </h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <a href="/" style="display: block; padding: 15px; background: #f5f5f5; border-radius: 6px; text-decoration: none; color: #667eea; font-weight: 500; text-align: center; transition: background 0.2s;">
                        🏠 Home
                    </a>
                    <a href="/store" style="display: block; padding: 15px; background: #f5f5f5; border-radius: 6px; text-decoration: none; color: #667eea; font-weight: 500; text-align: center; transition: background 0.2s;">
                        🛒 Store
                    </a>
                    <a href="/support" style="display: block; padding: 15px; background: #f5f5f5; border-radius: 6px; text-decoration: none; color: #667eea; font-weight: 500; text-align: center; transition: background 0.2s;">
                        📞 Support
                    </a>
                    <a href="/knowledge-base" style="display: block; padding: 15px; background: #f5f5f5; border-radius: 6px; text-decoration: none; color: #667eea; font-weight: 500; text-align: center; transition: background 0.2s;">
                        📚 Knowledge Base
                    </a>
                </div>
            </div>

        </div>

    </div>

</div>

<style>
    a {
        transition: all 0.3s ease;
    }

    a:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    @media (max-width: 600px) {
        div[style*="max-width: 800px"] {
            margin: 20px;
        }

        div[style*="font-size: 120px"] {
            font-size: 80px;
        }

        div[style*="grid-template-columns"] {
            grid-template-columns: 1fr;
        }
    }
</style>
