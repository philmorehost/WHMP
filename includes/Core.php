<?php
namespace BAF;

class Core {
    private static $instance = null;
    private $db = null;
    private $settings = [];

    private function __construct() {
        // Performance & Stability hardening
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', '30');
        ob_start(); // Standard buffering to avoid zlib conflicts

        date_default_timezone_set('UTC');
        $config_file = __DIR__ . '/../config.php';
        if (!file_exists($config_file)) {
            if (strpos($_SERVER['REQUEST_URI'], '/install/') === false) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                header("Location: $base_url/install/");
                exit;
            }
            return;
        }

        require_once $config_file;
        $this->init_db();
        $this->load_settings();
        $this->init_headers();
        $this->init_session();
    }

    private function init_headers() {
        if (!headers_sent()) {
            header("X-Frame-Options: SAMEORIGIN");
            header("X-Content-Type-Options: nosniff");
            header("X-XSS-Protection: 1; mode=block");
            header("Referrer-Policy: strict-origin-when-cross-origin");
            header("Content-Security-Policy: default-src 'self' https: data:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://pagead2.googlesyndication.com https://accounts.google.com https://www.googletagmanager.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; frame-src https://accounts.google.com https://www.youtube.com; connect-src 'self' https:;");
        }
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_db() {
        try {
            $this->db = new \PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (\PDOException $e) {
            die("Database connection failed. Please check your config.php.");
        }
    }

    private function load_settings() {
        if (!$this->db) return;
        $stmt = $this->db->query("SELECT `key`, `value` FROM settings");
        while ($row = $stmt->fetch()) {
            $this->settings[$row['key']] = $row['value'];
        }
    }

    private function init_session() {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                ini_set('session.cookie_secure', 1);
            }
            session_start();
        }

        // Prevent session fixation
        if (!isset($_SESSION['created_at'])) {
            $_SESSION['created_at'] = time();
        } elseif (time() - $_SESSION['created_at'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created_at'] = time();
        }
    }

    public function db() {
        return $this->db;
    }

    public function setting($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }

    // Security Helpers
    public static function escape($str) {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    public static function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify_csrf($token) {
        return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    public function is_admin() {
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    }

    public function render_favicon() {
        $title = trim($this->setting('site_title', 'BUYAFROBEATS'));
        $first_letter = strtoupper($title[0] ?? 'B');
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='#ffa326'/><text x='50%' y='54%' dominant-baseline='central' text-anchor='middle' font-family='Space Grotesk, sans-serif' font-weight='700' font-size='60' fill='#1a1815'>{$first_letter}</text></svg>";
        return "data:image/svg+xml," . rawurlencode($svg);
    }

    public function render_logo() {
        $title = trim($this->setting('site_title', 'Beat Zaza'));
        
        // Find where to split the color
        $split_at = -1;
        $space_index = strpos($title, ' ');

        if ($space_index !== false) {
            $split_at = $space_index;
        } else {
            // Find second capital letter (CamelCase)
            $caps_found = 0;
            for ($i = 0; $i < strlen($title); $i++) {
                if (ctype_upper($title[$i])) {
                    $caps_found++;
                    if ($caps_found === 2) {
                        $split_at = $i;
                        break;
                    }
                }
            }

            // Fallback for names like BUYBEATS
            if ($split_at === -1 && strpos(strtoupper($title), 'BUY') === 0 && strlen($title) > 3) {
                $split_at = 3;
            }
        }

        if ($split_at > 0 && $split_at < strlen($title)) {
            $part1 = substr($title, 0, $split_at);
            $part2 = substr($title, $split_at);
            return '<span>' . self::escape($part1) . '<span style="color:var(--accent)">' . self::escape($part2) . '</span></span>';
        }
        
        return '<span>' . self::escape($title) . '</span>';
    }

    public function render_seo($page_seo = []) {
        $site_title = $this->setting('site_title', 'BUYAFROBEATS');
        $site_title = str_replace(' ', '', $site_title);
        $title = !empty($page_seo['title']) ? $page_seo['title'] : $this->setting('global_meta_title', $site_title . " — Exclusive Beat Auctions");
        $desc = !empty($page_seo['description']) ? $page_seo['description'] : $this->setting('global_meta_description', 'A one-of-one Afrobeats auction house. Upload, bid, win, and the beat vanishes.');
        $keywords = !empty($page_seo['keywords']) ? $page_seo['keywords'] : $this->setting('global_meta_keywords', 'beats, auction, afrobeats, exclusive');

        $html = "<title>" . self::escape($title) . "</title>\n";
        $html .= "    <meta name=\"description\" content=\"" . self::escape($desc) . "\">\n";
        $html .= "    <meta name=\"keywords\" content=\"" . self::escape($keywords) . "\">\n";
        
        // OpenGraph
        $html .= "    <meta property=\"og:title\" content=\"" . self::escape($title) . "\">\n";
        $html .= "    <meta property=\"og:description\" content=\"" . self::escape($desc) . "\">\n";
        $html .= "    <meta property=\"og:type\" content=\"website\">\n";
        $html .= "    <meta property=\"og:url\" content=\"" . (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]\">\n";
        $html .= "    <meta property=\"og:image\" content=\"" . $this->get_site_url() . "/assets/img/share-card.png\">\n";

        // Twitter
        $html .= "    <meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        $html .= "    <meta name=\"twitter:title\" content=\"" . self::escape($title) . "\">\n";
        $html .= "    <meta name=\"twitter:description\" content=\"" . self::escape($desc) . "\">\n";
        $html .= "    <meta name=\"twitter:image\" content=\"" . $this->get_site_url() . "/assets/img/share-card.png\">\n";
        
        // AdSense
        $adsense_id = $this->setting('google_adsense_client');
        if ($adsense_id) {
            $html .= "    <script async src=\"https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=" . self::escape($adsense_id) . "\" crossorigin=\"anonymous\"></script>\n";
        }

        return $html;
    }

    public function get_site_url() {
        $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || 
                    (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] === 'on');
        $protocol = $is_https ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        
        // Calculate the base path relative to this file (which is in /includes/)
        $current_dir = str_replace('\\', '/', __DIR__);
        $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
        
        // Find the root of the project (one level up from /includes/)
        $project_root = dirname($current_dir);
        
        // The base path is the project root relative to the document root
        $base_path = str_replace($doc_root, '', $project_root);
        $base_path = rtrim($base_path, '/');
        
        return $protocol . "://" . $host . $base_path;
    }

    public function render_head_injection() {
        $injection = $this->setting('custom_header', '');
        if (empty($injection)) {
            $injection = $this->setting('header_injection', '');
        }
        
        $css = $this->setting('custom_css', '');
        if (!empty($css)) {
            $injection .= "\n<style>\n" . $css . "\n</style>\n";
        }
        
        return $injection;
    }

    public function render_footer_injection() {
        $injection = $this->setting('custom_footer', '');
        if (empty($injection)) {
            $injection = $this->setting('footer_injection', '');
        }
        return $injection;
    }
}
