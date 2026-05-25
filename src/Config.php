<?php

require_once(INCLUDE_DIR . 'class.forms.php');

/**
 * Invisible field that renders inline JS for provider switching and crawl button.
 */
class AIResponseSuggesterConfigScript extends FormField {
    static $widget = 'AIResponseSuggesterConfigScriptWidget';

    function hasData() { return false; }
    function isBlockLevel() { return true; }
}

class AIResponseSuggesterConfigScriptWidget extends Widget {
    function render($options = array()) {
        ?>
        <script type="text/javascript">
        (function($) {
            // Use setTimeout to ensure form is fully rendered
            $(document).ready(function() { setTimeout(function() {
                // --- Helper: find config field row by label text ---
                // Tries multiple selectors for compatibility across osTicket versions
                function findFieldRow(labelText) {
                    var $row = null;

                    // Strategy 1: td.multi-line (standard osTicket table layout)
                    $('td.multi-line').each(function() {
                        if ($(this).text().trim().indexOf(labelText) === 0) {
                            $row = $(this).closest('tr');
                            return false;
                        }
                    });
                    if ($row && $row.length) return $row;

                    // Strategy 2: .form-field div (newer osTicket versions)
                    $('.form-field').each(function() {
                        if ($(this).text().indexOf(labelText) !== -1) {
                            $row = $(this);
                            return false;
                        }
                    });
                    if ($row && $row.length) return $row;

                    // Strategy 3: label elements
                    $('label').each(function() {
                        if ($(this).text().trim().indexOf(labelText) === 0) {
                            $row = $(this).closest('tr, .form-field, .form-group');
                            return false;
                        }
                    });
                    if ($row && $row.length) return $row;

                    // Strategy 4: any td containing the label text
                    $('td').each(function() {
                        var t = $(this).children().first().text().trim();
                        if (!t) t = $(this).text().trim();
                        if (t.indexOf(labelText) === 0) {
                            $row = $(this).closest('tr');
                            return false;
                        }
                    });

                    return ($row && $row.length) ? $row : null;
                }

                function findFieldInput(labelText) {
                    var $row = findFieldRow(labelText);
                    if (!$row) return null;
                    return $row.find('select, input[type="text"], input[type="number"], textarea').first();
                }

                // --- Provider switching: show/hide API URL ---
                var $provider = findFieldInput('AI Provider');
                var $apiUrlRow = findFieldRow('API URL');

                function updateProviderVisibility() {
                    if (!$provider || !$apiUrlRow) return;
                    var val = $provider.val();
                    if (Array.isArray(val)) val = val[0];
                    if (val === 'custom') {
                        $apiUrlRow.show();
                    } else {
                        $apiUrlRow.hide();
                    }
                }

                if ($provider) {
                    $provider.on('change', updateProviderVisibility);
                    updateProviderVisibility();
                }

                // --- Crawl Now + View Content buttons ---
                var $crawlUrlRow = findFieldRow('Knowledge Base URL');
                if ($crawlUrlRow) {
                    var $btnWrap = $('<div style="margin-top:6px;"></div>');
                    var $btn = $('<button type="button" class="button" style="padding:4px 12px;">Crawl Now</button>');
                    var $viewBtn = $('<button type="button" class="button" style="padding:4px 12px;margin-left:8px;">View Content</button>');
                    var $status = $('<span style="margin-left:8px;color:#666;font-size:12px;"></span>');
                    $btnWrap.append($btn).append($viewBtn).append($status);

                    // Try multiple insertion points
                    var $input = $crawlUrlRow.find('input[type="text"], input[type="number"], input').first();
                    if ($input.length) {
                        $input.after($btnWrap);
                    } else {
                        // Fallback: append to the last cell or the row itself
                        var $lastCell = $crawlUrlRow.find('td').last();
                        if ($lastCell.length) {
                            $lastCell.append($btnWrap);
                        } else {
                            $crawlUrlRow.append($btnWrap);
                        }
                    }

                    // Load initial crawl status
                    $.ajax({
                        url: 'ajax.php/ai-response-suggester/crawl-status',
                        type: 'GET',
                        dataType: 'json',
                        success: function(resp) {
                            if (resp && resp.success && resp.pages > 0) {
                                $status.text(resp.pages + ' pages crawled' +
                                    (resp.last_crawled ? ' (last: ' + resp.last_crawled + ')' : ''));
                            }
                        }
                    });

                    // Crawl Now handler
                    $btn.on('click', function(e) {
                        e.preventDefault();
                        $btn.prop('disabled', true);
                        $status.css('color', '#666').text('Crawling...');

                        $.ajax({
                            url: 'ajax.php/ai-response-suggester/crawl',
                            type: 'POST',
                            dataType: 'json',
                            timeout: 300000
                        }).done(function(resp) {
                            if (resp && resp.success) {
                                var msg = 'Done! ' + resp.pages_crawled + ' pages crawled.';
                                if (resp.pages_summarized > 0) {
                                    msg += ' ' + resp.pages_summarized + ' summarized.';
                                }
                                if (resp.store_error) {
                                    msg += ' DB error: ' + resp.store_error;
                                    $status.css('color', '#856404').text(msg);
                                } else {
                                    $status.css('color', '#155724').text(msg);
                                }
                            } else {
                                $status.css('color', '#721c24')
                                    .text('Error: ' + (resp ? resp.error : 'Unknown'));
                            }
                        }).fail(function(xhr) {
                            var msg = 'Request failed';
                            try { msg = JSON.parse(xhr.responseText).error || msg; } catch(ex) {}
                            $status.css('color', '#721c24').text(msg);
                        }).always(function() {
                            $btn.prop('disabled', false);
                        });
                    });

                    // View Content handler
                    $viewBtn.on('click', function(e) {
                        e.preventDefault();
                        showContentModal();
                    });
                }

                // --- Content viewer modal ---
                function esc(text) {
                    if (!text) return '';
                    return $('<span>').text(text).html();
                }

                function showContentModal() {
                    // Remove existing modal
                    $('#ai-crawl-modal').remove();

                    var $overlay = $('<div id="ai-crawl-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;"></div>');
                    var $modal = $('<div style="background:#fff;border-radius:8px;width:90%;max-width:900px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 4px 20px rgba(0,0,0,0.3);"></div>');
                    var $header = $('<div style="padding:12px 16px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;"><strong>Crawled Content</strong><a href="#" id="ai-crawl-modal-close" style="font-size:20px;color:#999;text-decoration:none;">&times;</a></div>');
                    var $body = $('<div style="padding:16px;overflow-y:auto;flex:1;"><div style="color:#666;">Loading...</div></div>');
                    $modal.append($header).append($body);
                    $overlay.append($modal);
                    $('body').append($overlay);

                    $overlay.on('click', '#ai-crawl-modal-close', function(e) {
                        e.preventDefault();
                        $overlay.remove();
                    });
                    $overlay.on('click', function(e) {
                        if (e.target === $overlay[0]) $overlay.remove();
                    });

                    $.ajax({
                        url: 'ajax.php/ai-response-suggester/crawl-content',
                        type: 'GET',
                        dataType: 'json'
                    }).done(function(resp) {
                        if (!resp || !resp.success || !resp.pages || resp.pages.length === 0) {
                            $body.html('<div style="color:#999;padding:20px;text-align:center;">No crawled content found. Click "Crawl Now" first.</div>');
                            return;
                        }

                        var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                        html += '<thead><tr style="border-bottom:2px solid #ddd;text-align:left;">';
                        html += '<th style="padding:6px 8px;">URL</th>';
                        html += '<th style="padding:6px 8px;">Title</th>';
                        html += '<th style="padding:6px 8px;">Content</th>';
                        html += '<th style="padding:6px 8px;width:60px;">Depth</th>';
                        html += '<th style="padding:6px 8px;width:80px;"></th>';
                        html += '</tr></thead><tbody>';

                        resp.pages.forEach(function(page) {
                            var preview = page.summary_preview || page.content_preview || '';
                            var badge = page.summary_preview
                                ? '<span style="background:#d6eaf8;color:#1a5276;font-size:10px;padding:1px 5px;border-radius:8px;margin-left:4px;">summarized</span>'
                                : '';
                            html += '<tr style="border-bottom:1px solid #eee;">';
                            html += '<td style="padding:6px 8px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(page.url) + '"><a href="' + esc(page.url) + '" target="_blank" style="color:#5B9BD5;">' + esc(page.url.replace(/^https?:\/\/[^/]+/, '')) + '</a></td>';
                            html += '<td style="padding:6px 8px;">' + esc(page.title) + '</td>';
                            html += '<td style="padding:6px 8px;color:#555;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(preview) + '">' + esc(preview.substring(0, 120)) + (preview.length > 120 ? '...' : '') + badge + '</td>';
                            html += '<td style="padding:6px 8px;text-align:center;">' + page.depth + '</td>';
                            html += '<td style="padding:6px 8px;text-align:center;"><a href="#" class="ai-crawl-delete" data-id="' + page.id + '" style="color:#c0392b;font-size:12px;">Delete</a></td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table>';
                        $body.html(html);

                        // Delete handler
                        $body.on('click', '.ai-crawl-delete', function(e) {
                            e.preventDefault();
                            var $link = $(this);
                            var id = $link.data('id');
                            if (!confirm('Delete this crawled page?')) return;

                            $.ajax({
                                url: 'ajax.php/ai-response-suggester/crawl-delete',
                                type: 'POST',
                                data: { page_id: id },
                                dataType: 'json'
                            }).done(function(resp) {
                                if (resp && resp.success) {
                                    $link.closest('tr').fadeOut(200, function() { $(this).remove(); });
                                } else {
                                    alert('Delete failed: ' + (resp ? resp.error : 'Unknown'));
                                }
                            });
                        });

                    }).fail(function() {
                        $body.html('<div style="color:#c0392b;padding:20px;">Failed to load content.</div>');
                    });
                }
            }, 150); });
        })(jQuery);
        </script>
        <?php
    }
}

class AIResponseSuggesterConfig extends PluginConfig {

    function getFormOptions() {
        return array(
            'title' => __('AI Response Suggester Settings'),
            'instructions' => __('Configure AI provider, crawling, and response settings.'),
        );
    }

    function getOptions() {
        return array(
            'ai_provider' => new ChoiceField(array(
                'label' => __('AI Provider'),
                'default' => 'openai',
                'choices' => array(
                    'openai' => 'OpenAI',
                    'anthropic' => 'Anthropic',
                    'custom' => 'Custom (OpenAI-compatible)',
                ),
                'hint' => __('Select your AI provider.'),
            )),
            'api_key' => new TextboxField(array(
                'label' => __('API Key'),
                'required' => true,
                'configuration' => array(
                    'size' => 80,
                    'length' => 255,
                    'placeholder' => 'sk-...',
                ),
                'hint' => __('Your API key for the selected provider.'),
            )),
            'api_url' => new TextboxField(array(
                'label' => __('API URL'),
                'required' => false,
                'configuration' => array(
                    'size' => 80,
                    'length' => 500,
                    'placeholder' => 'https://api.example.com/v1/chat/completions',
                ),
                'hint' => __('Required only for "Custom" provider. Auto-set for OpenAI/Anthropic.'),
            )),
            'model' => new TextboxField(array(
                'label' => __('Model'),
                'default' => 'gpt-4o-mini',
                'required' => true,
                'configuration' => array(
                    'size' => 40,
                    'length' => 100,
                ),
                'hint' => __('Model name (e.g. gpt-4o-mini, claude-sonnet-4-20250514).'),
            )),
            'system_prompt' => new TextareaField(array(
                'label' => __('Custom System Prompt'),
                'required' => false,
                'configuration' => array(
                    'rows' => 5,
                    'html' => false,
                    'placeholder' => __('Optional additional instructions for the AI.'),
                ),
                'hint' => __('Appended to the default system prompt.'),
            )),
            'response_template' => new TextareaField(array(
                'label' => __('Response Template'),
                'required' => false,
                'configuration' => array(
                    'rows' => 5,
                    'html' => false,
                    'placeholder' => "Hello {user_name},\n\n{ai_text}\n\nBest regards,\n{agent_name}",
                ),
                'hint' => __('Wrap AI output. Tokens: {ai_text}, {ticket_number}, {user_name}, {agent_name}.'),
            )),
            'confidence_threshold' => new TextboxField(array(
                'label' => __('Confidence Threshold'),
                'default' => '60',
                'required' => true,
                'validator' => 'number',
                'configuration' => array('size' => 10, 'length' => 3),
                'hint' => __('Show low-confidence warning below this score (0-100).'),
            )),
            'max_canned_responses' => new TextboxField(array(
                'label' => __('Max Canned Responses'),
                'default' => '15',
                'required' => true,
                'validator' => 'number',
                'configuration' => array('size' => 10, 'length' => 3),
                'hint' => __('Maximum canned responses sent to AI for analysis.'),
            )),
            'temperature' => new TextboxField(array(
                'label' => __('Temperature'),
                'default' => '0.3',
                'required' => false,
                'configuration' => array('size' => 10, 'length' => 4),
                'hint' => __('Controls randomness (0.0-2.0). Lower = more deterministic.'),
            )),
            'timeout' => new TextboxField(array(
                'label' => __('API Timeout (seconds)'),
                'default' => '30',
                'required' => true,
                'validator' => 'number',
                'configuration' => array('size' => 10, 'length' => 3),
                'hint' => __('Maximum time to wait for AI response.'),
            )),
            'crawl_url' => new TextboxField(array(
                'label' => __('Knowledge Base URL'),
                'required' => false,
                'configuration' => array(
                    'size' => 80,
                    'length' => 500,
                    'placeholder' => 'https://example.com/docs',
                ),
                'hint' => __('Base URL to crawl for knowledge base content. Use the "Crawl Now" button after saving.'),
            )),
            'crawl_depth' => new TextboxField(array(
                'label' => __('Crawl Depth'),
                'default' => '3',
                'required' => false,
                'validator' => 'number',
                'configuration' => array('size' => 10, 'length' => 2),
                'hint' => __('Maximum link depth to follow (1-10).'),
            )),
            'crawl_max_pages' => new TextboxField(array(
                'label' => __('Max Pages to Crawl'),
                'default' => '50',
                'required' => false,
                'validator' => 'number',
                'configuration' => array('size' => 10, 'length' => 3),
                'hint' => __('Maximum number of pages to crawl (1-200).'),
            )),
            'crawl_use_ai' => new BooleanField(array(
                'label' => __('Summarize with AI'),
                'default' => false,
                'configuration' => array(
                    'desc' => __('Send each crawled page through the AI to extract support-relevant content. Uses API tokens per page.'),
                ),
            )),
            'crawl_respect_robots' => new BooleanField(array(
                'label' => __('Respect robots.txt'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Fetch /robots.txt from the target host and skip URLs disallowed for our crawler. Disable only when crawling internal documentation you control.'),
                ),
            )),
            'crawl_skip_patterns' => new TextareaField(array(
                'label' => __('Skip Patterns'),
                'required' => false,
                'configuration' => array(
                    'rows' => 4,
                    'html' => false,
                    'placeholder' => "/admin/\n*/drafts/*\n/private$",
                ),
                'hint' => __('One URL pattern per line. Matched against the path+query. Wildcards: * = any chars, $ = end-of-path. Patterns starting with / are anchored to the path start; otherwise they may match anywhere.'),
            )),
            'enable_logging' => new BooleanField(array(
                'label' => __('Enable Debug Logging'),
                'default' => false,
                'configuration' => array(
                    'desc' => __('Log AI requests and responses to PHP error log.'),
                ),
            )),
            '_config_script' => new AIResponseSuggesterConfigScript(array(
                'label' => ' ',
                'required' => false,
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        $result = true;

        if ($config['ai_provider'] === 'openai') {
            $config['api_url'] = 'https://api.openai.com/v1/chat/completions';
        } elseif ($config['ai_provider'] === 'anthropic') {
            $config['api_url'] = 'https://api.anthropic.com/v1/messages';
        } elseif ($config['ai_provider'] === 'custom' && empty($config['api_url'])) {
            $errors['api_url'] = __('API URL is required for Custom provider.');
            $result = false;
        }

        if (isset($config['temperature'])) {
            $temp = (float) $config['temperature'];
            if ($temp < 0.0 || $temp > 2.0) {
                $errors['temperature'] = __('Temperature must be between 0.0 and 2.0.');
                $result = false;
            }
        }

        if (isset($config['confidence_threshold'])) {
            $ct = (int) $config['confidence_threshold'];
            if ($ct < 0 || $ct > 100) {
                $errors['confidence_threshold'] = __('Confidence threshold must be between 0 and 100.');
                $result = false;
            }
        }

        if (isset($config['crawl_depth'])) {
            $cd = (int) $config['crawl_depth'];
            if ($cd < 1 || $cd > 10) {
                $errors['crawl_depth'] = __('Crawl depth must be between 1 and 10.');
                $result = false;
            }
        }

        if (isset($config['crawl_max_pages'])) {
            $cmp = (int) $config['crawl_max_pages'];
            if ($cmp < 1 || $cmp > 200) {
                $errors['crawl_max_pages'] = __('Max pages must be between 1 and 200.');
                $result = false;
            }
        }

        return $result;
    }
}
