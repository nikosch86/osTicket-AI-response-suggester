<?php

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(__DIR__ . '/Config.php');

class AIResponseSuggesterPlugin extends Plugin {
    var $config_class = 'AIResponseSuggesterConfig';

    private static $active_config = null;
    private static $configs = array();

    function bootstrap() {
        Signal::connect('object.view', array($this, 'onObjectView'), 'Ticket');
        Signal::connect('ajax.scp', array($this, 'onAjaxScp'));

        $cfg = $this->getConfig();
        if ($cfg) {
            self::$active_config = $cfg;
            $inst = $cfg->getInstance();
            if ($inst && $inst->getId()) {
                self::$configs[$inst->getId()] = $cfg;
            }
        }

        require_once(__DIR__ . '/ContentStore.php');
        ContentStore::ensureTable();
    }

    public static function getActiveConfig() {
        return self::$active_config;
    }

    public static function getAllConfigs() {
        return self::$configs;
    }

    function onObjectView($object, &$data) {
        static $included = false;
        if ($included) return;
        $included = true;

        $cfg = $this->getConfig();
        $threshold = $cfg ? (int) $cfg->get('confidence_threshold') : 60;

        // Inline CSS — nginx blocks /include/ so external files return 403
        echo '<style type="text/css">';
        readfile(__DIR__ . '/../assets/css/style.css');
        echo '</style>';

        // Inline config
        ?>
        <script type="text/javascript">
        window.AIResponseSuggester = window.AIResponseSuggester || {};
        window.AIResponseSuggester.ajaxEndpoint = 'ajax.php/ai-response-suggester';
        window.AIResponseSuggester.confidenceThreshold = <?php echo $threshold; ?>;
        </script>
        <?php

        // Inline JS
        echo '<script type="text/javascript">';
        readfile(__DIR__ . '/../assets/js/main.js');
        echo '</script>';
    }

    function onAjaxScp($dispatcher) {
        require_once(__DIR__ . '/AjaxController.php');
        $dispatcher->append(
            url_post('^/ai-response-suggester/suggest$', array('AIResponseSuggesterAjax', 'suggest'))
        );
        $dispatcher->append(
            url_post('^/ai-response-suggester/crawl$', array('AIResponseSuggesterAjax', 'crawl'))
        );
        $dispatcher->append(
            url('^/ai-response-suggester/crawl-status$', array('AIResponseSuggesterAjax', 'crawlStatus'))
        );
        $dispatcher->append(
            url('^/ai-response-suggester/crawl-content$', array('AIResponseSuggesterAjax', 'crawlContent'))
        );
        $dispatcher->append(
            url_post('^/ai-response-suggester/crawl-delete$', array('AIResponseSuggesterAjax', 'crawlDelete'))
        );
    }
}
