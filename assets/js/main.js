(function($) {
    'use strict';

    var AISuggester = {
        config: {},
        $panel: null,
        $btn: null,

        init: function() {
            this.config = window.AIResponseSuggester || {};
            this.config.ajaxEndpoint = this.config.ajaxEndpoint || 'ajax.php/ai-response-suggester';
            this.config.confidenceThreshold = this.config.confidenceThreshold || 60;
            this.injectButton();
            this.bindEvents();
        },

        injectButton: function() {
            if ($('#ai-suggester-btn').length > 0) return;

            // Find the canned response select and its Select2 wrapper
            var $select = $('#cannedResp');
            var $target = null;
            if ($select.length) {
                // Insert after the Select2 visible container (last sibling span.select2)
                var $s2 = $select.siblings('.select2').last();
                if ($s2.length) {
                    $target = $s2;
                } else {
                    $target = $select.closest('div[data-select2-id]');
                    if (!$target.length) {
                        $target = $select.parent();
                    }
                }
            }
            if (!$target || !$target.length) {
                $target = $('label[for="response"]');
            }
            if (!$target.length) return;

            var btnHtml =
                '<span id="ai-suggester-container" style="display:inline-block; margin-left: 10px; vertical-align: top;">' +
                    '<button type="button" id="ai-suggester-btn" class="ai-suggester-btn">' +
                        '<i class="icon-magic"></i> AI Suggest Response' +
                    '</button>' +
                    '<span id="ai-suggester-loading" class="ai-suggester-loading" style="display:none;">' +
                        '<span class="ai-spinner"></span> Analyzing...' +
                    '</span>' +
                '</span>';

            $target.after(btnHtml);

            var panelHtml = '<div id="ai-suggester-panel" class="ai-suggester-panel" style="display:none;"></div>';
            var $replyArea = $('#response').closest('.reply-content, .thread-body, form');
            if ($replyArea.length) {
                $replyArea.before(panelHtml);
            } else {
                $target.closest('div').after(panelHtml);
            }

            this.$btn = $('#ai-suggester-btn');
            this.$panel = $('#ai-suggester-panel');
        },

        bindEvents: function() {
            var self = this;
            var ns = '.aiSuggester';

            // Unbind first to prevent duplicate handlers
            $(document).off(ns);

            $(document).on('click' + ns, '#ai-suggester-btn', function(e) {
                e.preventDefault();
                self.suggest();
            });

            $(document).on('click' + ns, '#ai-suggester-use', function(e) {
                e.preventDefault();
                var text = self.$panel.data('response-text') || '';
                self.insertIntoEditor(text);
                self.$panel.slideUp(200);
            });

            $(document).on('click' + ns, '#ai-suggester-close', function(e) {
                e.preventDefault();
                self.$panel.slideUp(200);
            });
        },

        getTicketId: function() {
            var match = window.location.search.match(/id=(\d+)/);
            if (match) return match[1];
            var $input = $('input[name="id"]');
            return $input.length ? $input.val() : null;
        },

        suggest: function() {
            var self = this;
            var ticketId = this.getTicketId();

            if (!ticketId) {
                alert('Could not determine ticket ID.');
                return;
            }

            this.$btn.prop('disabled', true);
            $('#ai-suggester-loading').show();
            this.$panel.hide();

            $.ajax({
                url: this.config.ajaxEndpoint + '/suggest',
                type: 'POST',
                data: { ticket_id: ticketId },
                dataType: 'json'
            }).done(function(resp) {
                if (resp && resp.success) {
                    self.showSuggestion(resp);
                } else {
                    self.showError(resp ? resp.error : 'Unknown error');
                }
            }).fail(function(xhr) {
                var msg = 'Request failed';
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r && r.error) msg = r.error;
                } catch (e) {}
                self.showError(msg);
            }).always(function() {
                self.$btn.prop('disabled', false);
                $('#ai-suggester-loading').hide();
            });
        },

        showSuggestion: function(data) {
            var confidence = data.confidence || 0;
            var threshold = this.config.confidenceThreshold;
            var level = confidence >= 80 ? 'high' : (confidence >= 60 ? 'medium' : 'low');

            var html = '<div class="ai-suggester-box">';

            // Header row
            html += '<div class="ai-suggester-header">';
            html += '<span class="ai-confidence ai-confidence-' + level + '">' +
                    'Confidence: ' + confidence + '%</span>';

            if (data.based_on_canned_id) {
                html += ' <span class="ai-badge ai-badge-canned">Based on: ' +
                        this.escapeHtml(data.based_on_canned_title || 'Canned #' + data.based_on_canned_id) +
                        '</span>';
            } else {
                html += ' <span class="ai-badge ai-badge-generated">Freely Generated</span>';
            }

            html += '<a href="#" id="ai-suggester-close" class="ai-suggester-close">&times;</a>';
            html += '</div>';

            // Low confidence warning
            if (confidence < threshold) {
                html += '<div class="ai-warning">' +
                        'Low confidence (' + confidence + '%) - below threshold (' + threshold + '%). ' +
                        'Review carefully before using.' +
                        '</div>';
            }

            // Reasoning
            if (data.reasoning) {
                html += '<div class="ai-reasoning">' + this.escapeHtml(data.reasoning) + '</div>';
            }

            // Response preview — escape then convert newlines to <br> for display
            var previewText = this.escapeHtml(data.response_text || '').replace(/\n/g, '<br>');
            html += '<div class="ai-response-preview">' + previewText + '</div>';

            // Debug: full prompt
            if (data.debug_prompt && data.debug_prompt.length) {
                html += '<details style="margin-bottom:10px;font-size:12px;">';
                html += '<summary style="cursor:pointer;color:#888;">Debug: View Full Prompt</summary>';
                html += '<div style="background:#f5f5f5;border:1px solid #ddd;border-radius:4px;padding:10px;margin-top:6px;max-height:300px;overflow-y:auto;white-space:pre-wrap;font-family:monospace;font-size:11px;">';
                for (var i = 0; i < data.debug_prompt.length; i++) {
                    var m = data.debug_prompt[i];
                    html += '<strong>[' + this.escapeHtml(m.role) + ']</strong>\n' + this.escapeHtml(m.content) + '\n\n';
                }
                html += '</div></details>';
            }

            // Action buttons
            html += '<div class="ai-actions">';
            html += '<button type="button" id="ai-suggester-use" class="ai-btn-use">Use This Response</button>';
            html += '</div>';

            html += '</div>';

            this.$panel.html(html).data('response-text', data.response_text || '').slideDown(200);
        },

        showError: function(msg) {
            var html = '<div class="ai-suggester-box">' +
                '<div class="ai-suggester-header">' +
                    '<span class="ai-confidence ai-confidence-low">Error</span>' +
                    '<a href="#" id="ai-suggester-close" class="ai-suggester-close">&times;</a>' +
                '</div>' +
                '<div class="ai-error">' + this.escapeHtml(msg) + '</div>' +
                '</div>';
            this.$panel.html(html).slideDown(200);
        },

        insertIntoEditor: function(text) {
            var $ta = $('#response');
            if (!$ta.length) {
                alert('Response editor not found.');
                return;
            }

            // Ensure Post Reply tab is active
            var $postBtn = $('a.post-response.action-button').first();
            if ($postBtn.length && !$postBtn.hasClass('active')) {
                try { $postBtn.trigger('click'); } catch (e) {}
            }

            // Try Redactor source.setCode
            try {
                if (typeof $ta.redactor === 'function' && $ta.hasClass('richtext')) {
                    $ta.redactor('source.setCode', text);
                    return;
                }
            } catch (e) {}

            // Try Redactor insertion.insertHtml
            try {
                var redactor = $ta.data('redactor');
                if (redactor && redactor.insertion && typeof redactor.insertion.insertHtml === 'function') {
                    redactor.insertion.insertHtml(text);
                    return;
                }
            } catch (e) {}

            // Fallback: plain textarea
            $ta.val(text).trigger('change');
        },

        escapeHtml: function(text) {
            if (!text) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    };

    $(document).ready(function() {
        setTimeout(function() {
            AISuggester.init();
        }, 500);
    });

})(jQuery);
