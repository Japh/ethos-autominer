<?php
require('vendor/autoload.php');

use Flintstone\Flintstone;
use Flintstone\Formatter\JsonFormatter;

class Gruve {
    public $rig;

    private $options;

    private $settings;
    private $stats;
    private $cache;
    private $configs;

    private $home_dir;
    private $autominer_dir;
    private $data_dir;
    private $configs_dir;

    public function __construct($options = []) {
        $this->options = $options;
        $this->rig = gethostname();

        $this->home_dir = '/home/ethos';
        if ($this->options['dry_run']) {
            $this->home_dir = './home';
        }

        $this->autominer_dir = $this->home_dir . '/.gruve';
        $this->data_dir = $this->autominer_dir . '/data';
        $this->configs_dir = $this->autominer_dir . '/configs';
        $this->log_file = $this->autominer_dir . '/switcher-' . date('Ymd') . '.log';

        if(!file_exists($this->autominer_dir)) {
            mkdir($this->autominer_dir);
            if(!file_exists($this->data_dir)) {
                mkdir($this->data_dir);
            }
            if(!file_exists($this->configs_dir)) {
                mkdir($this->configs_dir);
            }
        }

        $data_options = [
            'dir' => $this->data_dir,
            'ext' => 'db',
            'gzip' => true,
            'formatter' => new JsonFormatter()
        ];

        $this->settings = new Flintstone('settings', $data_options);
        $this->stats = new Flintstone('stats', $data_options);
        $this->cache = new Flintstone('cache', $data_options);

        $default_settings = [
            'mine_duration' => 3600,
            'switch_threshold' => 25,
            'cache_expiry' => 300,
            'pushbullet_token' => '',
            'pushover_token' => '',
            'pushover_user' => '',
        ];

        foreach($default_settings as $setting => $val) {
            $val = (!empty($this->options[$setting]) ? $this->options[$setting] : $val);
            $this->settings->set($setting, $val);
        }

        if (!empty($this->options['whattomine_url']) && $this->options['whattomine_url'] != $this->get_whattomine_url()) {
            $this->set_whattomine_url($this->options['whattomine_url']);
        }

        $this->configs = json_decode(file_get_contents($this->autominer_dir . '/config.json'));

        if (empty($this->configs)) {
            exit('No configuration files found. Cannot continue.');
        }

        if (empty($this->options['dry_run'])) {
            $this->gather();
        }
    }

    public function coin_check() {
        if ($this->is_locked()) {
            return false;
        } else {
            $this->settings->set('is_locked', 1);
            $current_coin = $this->get_current_coin();
            $duration = $this->get_mine_duration($current_coin['tag']);
            $last_switch = (empty($this->settings->get('last_switch')) ? 0 : $this->settings->get('last_switch'));
            $time_diff = time() - $last_switch;
            if ($time_diff < $duration) {
                // $message = 'Mining ' . $current_coin['tag'] . ' for ' . $this->human_time($time_diff) . ' of at least ' . $this->human_time($duration) . '.';
                // $this->log($message);
                $this->settings->set('is_locked', 0);
                return false;
            } else {
                $most_profitable_coin = $this->get_most_profitable_coin();
                if ($current_coin['tag'] == $most_profitable_coin['tag']) {
                    // $message = 'Mined ' . $current_coin['tag'] . ' for ' . $this->human_time($time_diff) . ' so far, still most profitable.';
                    // $this->log($message);
                    $this->settings->set('is_locked', 0);
                    return false;
                } else {
                    $profitability_threshold = $this->settings->get('switch_threshold');
                    $profitability_diff = $most_profitable_coin['profitability'] - $current_coin['profitability'];
                    if ($profitability_diff < $profitability_threshold) {
                        // $message = 'Mined ' . $current_coin['tag'] . ' for ' . $this->human_time($time_diff) . ' so far, ' . $most_profitable_coin['tag'] . ' is now ' . $profitability_diff . '% more profitable at ' . $most_profitable_coin['profitability'] . '%.';
                        // $this->log($message);
                        $this->settings->set('is_locked', 0);
                        return false;
                    } else {
                        $new_duration = $this->get_mine_duration($most_profitable_coin['tag']);
                        $message = 'Mined ' . $current_coin['tag'] . ' for ' . $this->human_time($time_diff) . ', switching to ' . $most_profitable_coin['tag'] . ' (' . $profitability_diff . '% more profitable) for at least ' . $this->human_time($new_duration) . '.';
                        $this->log($message, true);
                        $this->switch_to_coin($most_profitable_coin);
                        $this->settings->set('is_locked', 0);
                        return true;
                    }
                }
            }
        }
    }

    public function switch_to_coin($coin) {
        $config = $this->get_coin_config($coin['tag']);

        $config_file = $this->generate_config($coin);
        $local_conf = '';

        foreach($config_file as $key => $val) {
            if(!empty($val)) {
                $local_conf .= $key . (strpos($val, '=') === 0 ? '' : ' ') . $val . "\r\n";
            }
        }

        file_put_contents($this->home_dir . '/local.conf', $local_conf);

        if (empty($this->options['dry_run'])) {
            shell_exec('/opt/ethos/bin/disallow 2>&1');
            sleep(5);
            shell_exec('/opt/ethos/bin/minestop 2>&1');
            sleep(5);
            shell_exec('/opt/ethos/bin/allow 2>&1');
        }

        $this->set_current_coin($coin);
        $this->set_last_switch(time());
    }

    public function generate_config($coin) {
        $config = $this->configs->global;
        $algorithm = strtolower($coin['algorithm']);
        $algo_config = $this->configs->algorithms->$algorithm;
        $tag = $coin['tag'];
        $coin_config = $this->configs->coins->$tag;

        foreach($algo_config as $key => $val) {
            $config->$key = $val;
        }

        foreach($coin_config as $key => $val) {
            $config->$key = $val;
        }

        unset($config->mine_duration);

        return $config;
    }

    public function set_last_switch($secs) {
        $this->settings->set('last_switch', $secs);
    }

    public function get_last_switch() {
        return $this->settings->get('last_switch');
    }

    public function set_mine_duration($duration) {
        $this->settings->set('mine_duration', $duration);
    }

    public function get_mine_duration($coin = '') {
        $duration =$this->settings->get('mine_duration');
        if (!empty($coin)) {
            $config = $this->get_coin_config($coin);
            if (!empty($config->mine_duration)) {
                $duration = $config->mine_duration;
            }
        }
        return $duration;
    }

    public function set_whattomine_url($url) {
        if(strpos($url, '.json') !== false) {
            $this->settings->set('whattomine_url', $url);
        } else {
            $this->settings->set('whattomine_url', str_replace('whattomine.com/coins', 'whattomine.com/coins.json', $url));
        }
    }

    public function get_whattomine_url() {
        return $this->settings->get('whattomine_url');
    }

    public function set_current_coin($coin) {
        $this->settings->set('current_coin', $coin);
    }

    public function get_current_coin() {
        return $this->settings->get('current_coin');
    }

    public function is_locked() {
        return $this->settings->get('is_locked');
    }

    public function get_most_profitable_coin() {
        $coins = $this->get_whattomine_profitability();
        $most_profitable_coin = 0;

        foreach($coins['coins'] as $label => $coin) {
            if (!in_array($coin['tag'], $this->get_configured_tags())) {
                continue;
            } elseif ($coin['lagging']) {
                continue;
            } else {
                $profitability = floatval($coin['profitability']);
                if ($profitability > $most_profitable_coin) {
                    $most_profitable_coin = $coin;
                }
            }
        }

        return $most_profitable_coin;
    }

    private function get_configured_tags() {
        $tags = [];
        foreach($this->configs->coins as $label => $config) {
            array_push($tags, $label);
        }
        return $tags;
    }

    private function get_coin_config($coin) {
        $config = $this->configs->coins->$coin;
        return $config;
    }

    private function get_whattomine_profitability() {
        $url = $this->settings->get('whattomine_url');
        if (empty($url)) {
            return false;
        }

        $url_hash = md5($url);
        $cache_hash = $this->get_cache($url_hash);
        $json_coins = '';

        if (!empty($cache_hash)) {
            $json_coins = $cache_hash['json_coins'];
        } else {
            $json_coins = json_decode(file_get_contents($url));
            $cache = [];
            $cache['json_coins'] = $json_coins;
            $this->set_cache($url_hash, $cache);
            $json_coins = $this->get_cache($url_hash);
            $json_coins = $json_coins['json_coins'];
        }

        return $json_coins;
    }

    public function set_cache($key, $data, $expiry = null) {
        if (empty($expiry)) {
            $expiry = $this->settings->get('cache_expiry');
        }
        $this->cache->set($key, [
            'timestamp' => time(),
            'expiry' => $expiry,
            'data' => $data
        ]);
    }

    public function get_cache($key) {
        $cache = $this->cache->get($key);
        if (time() - $cache['timestamp'] < $cache['expiry']) {
            return $cache['data'];
        } else {
            return false;
        }
    }

    private function gather() {
        global $autominer_dir, $rig;
        $stats = [];

        $timestamp = time();

        $stats_output = shell_exec('/opt/ethos/bin/show stats');

        $stats_lines = explode("\n", $stats_output);

        $stat = new \stdClass();
        $previous_key = '';
        $key = '';
        $previous_val = '';
        for ($i = 0; $i < count($stats_lines); $i++) {
            $data = explode(': ', $stats_lines[$i]);

            if (empty(trim($data[0])) && empty(trim($data[1])))
                continue;

            if (empty($data[1])) {
                $data[1] = $data[0];
                $data[0] = '';
            }

            if (!empty(trim($data[0]))) {
                $key = trim($data[0]);
                $previous_key = $key;
                $val = trim($data[1]);
                $previous_val = $val;
            } else {
                $key = $previous_key;
                if (is_array($stat->data->$key)) {
                    $val = $stat->data->$key;
                    array_push($val, trim($data[1]));
                } else {
                    $val = [];
                    array_push($val, $previous_val);
                    array_push($val, trim($data[1]));
                }
            }
            if (empty($stat->data)) {
                $stat->data = new \stdClass();
            }
            $stat->data->$key = $val;
        }
        array_push($stats, $stat->data);

        $this->stats->set($timestamp, $stats);
    }

    public function human_time($secs) {
        $s = $secs % 60;
        $m = floor(($secs % 3600) / 60);
        $h = floor(($secs % 86400) / 3600);
        $d = floor($secs / 86400);

        $output = '';
        $output .= (!empty($d) ? $d . ' day' . ($d == 1 ? '' : 's') . ' ' : '');
        $output .= (!empty($h) ? $h . ' hour' . ($h == 1 ? '' : 's') . ' ' : '');
        $output .= (!empty($m) ? $m . ' minute' . ($m == 1 ? '' : 's') . ' ' : '');
        $output .= (!empty($s) ? $s . ' second' . ($s == 1 ? '' : 's') : '');

        return trim($output);
    }

    public function log($message, $notification = false) {
        if ($notification) {
            $alert_message = '[' . $this->rig . '] ' . $message;
            if (!empty($this->settings->get('pushbullet_token'))) {
                $this->pushbullet($alert_message);
            }
            if (!empty($this->settings->get('pushover_token')) && !empty($this->settings->get('pushover_user'))) {
                $this->pushover($alert_message);
            }
        }
        $log_message = date('Y-m-d H:i:s') . ' - [' . $this->rig . '] ' . $message;
        file_put_contents($this->log_file, $log_message . "\r\n", FILE_APPEND | LOCK_EX);
    }

    function pushbullet($msg) {
        $data = json_encode(array(
            'type' => 'note',
            'title' => 'Gruve',
            'body' => $msg,
        ));

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.pushbullet.com/v2/pushes');
        curl_setopt($curl, CURLOPT_USERPWD, $this->settings->get('pushbullet_token'));
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($data)]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_exec($curl);
        curl_close($curl);
    }

    function pushover($msg) {
        $data = array(
            'token' => $this->settings->get('pushover_token'),
            'user' => $this->settings->get('pushover_user'),
            'title' => 'Gruve',
            'message' => $msg,
        );

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.pushover.net/1/messages.json');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SAFE_UPLOAD, true);
        curl_exec($curl);
        curl_close($curl);
    }

    public function setup($path = '') {
        if (file_exists($this->autominer_dir . '/config.json')) {
            exit('You already have a config.json, please rename it to back it up before running setup.' . "\n");
        }

        if (empty($path)) {
            $path = $this->home_dir . '/ethos-autominer';
        }

        $config = json_decode(file_get_contents($path . '/config.sample.json'));

        echo "Please enter the symbol for the coin you're currently mining: ";
        $symbol = fgets(STDIN, 2048); // read the special file to get the user input from keyboard
        $symbol = trim($symbol);

        $setup_configs = [];
        $setup_cfg = new \stdClass();
        $setup_cfg->coin = strtoupper($symbol);
        $setup_cfg->config = strtolower($symbol) . '.conf';
        array_push($setup_configs, $setup_cfg);

        copy($this->home_dir . '/local.conf', $this->autominer_dir . '/configs/' . $setup_cfg->config);

        $config->configs = $setup_configs;

        echo "Please go to whattomine.com, enter your rig details, and press 'calculate', then copy and paste the very long web address here:\n";
        $whattomine_paste = fgets(STDIN, 2048);

        if(strpos($whattomine_paste, '.json') !== false) {
            $this->settings->set('whattomine_url', $whattomine_paste);
        } else {
            $this->settings->set('whattomine_url', str_replace('whattomine.com/coins', 'whattomine.com/coins.json', $whattomine_paste));
        }

        file_put_contents($this->autominer_dir . '/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        exit("\n=> Setup complete.\n");
    }
}
