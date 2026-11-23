(function ($) {
    'use strict';
    var validator = '.vf-validator-setting select';

    function removeEditor() {
        var $textarea = $(this), editor = $textarea.data('vfEditor');
        if (editor) {
            editor.toTextArea();
            $textarea.removeData('vfEditor');
        }
    }

    function refreshEditor() {
        var editor = $(this).data('vfEditor');
        if (editor) editor.refresh();
    }

    function updateRule() {
        var mode = $(this).val();
        var $rule = $(this).closest('.vf-validator-setting').siblings('.vf-rule-setting');
        var $textarea = $rule.find('textarea');
        $rule.toggle(mode === 'regex' || mode === 'php');
        if (mode !== 'php') {
            $textarea.each(removeEditor);
            return;
        }
        if (!$textarea.length || $textarea.data('vfEditor') || !window.wp || !wp.codeEditor || !acfVfSettings.editor) return;
        var editor = wp.codeEditor.initialize($textarea[0], acfVfSettings.editor);
        if (editor) {
            $textarea.data('vfEditor', editor.codemirror);
            editor.codemirror.on('change', function (cm) { cm.save(); });
            setTimeout(refreshEditor.bind($textarea[0]), 0);
        }
    }

    function initialize($scope) {
        $scope = $scope && $scope.$el ? $scope.$el : $($scope || document);
        $scope.find(validator).addBack(validator).each(updateRule);
    }
    $(document).on('change', validator, updateRule);
    $(document).on('click', '.acf-tab-button', function () {
        var $field = $(this).closest('.acf-field-object');
        setTimeout(function () {
            $field.find('.vf-rule-setting textarea').each(function () {
                if ($(this).closest('.acf-field-object')[0] === $field[0]) refreshEditor.call(this);
            });
        }, 0);
    });
    $(document).on('change', '.vf-type-setting select', function () {
        var $select = $(this), $field = $select.closest('.acf-field-object'),
            $panel = $field.find('.vf-native-settings').first();
        var previous = $panel.data('vfRequest');
        if (previous) previous.abort();
        $select.prop('disabled', true);
        var request = $.post(acf.get('ajaxurl'), {
            action: 'acf_vf_sub_settings', nonce: acfVfSettings.nonce,
            type: $select.val(), key: $panel.attr('data-key'), prefix: $panel.attr('data-prefix')
        }).done(function (response) {
            if (!response.success) return;
            acf.doAction('remove', $panel);
            $panel.html(response.data.html);
            acf.doAction('append', $panel);
        }).fail(function (xhr, status) {
            if (status !== 'abort') window.alert('The input settings could not be loaded. Please reload before saving.');
        }).always(function () { $select.prop('disabled', false); $panel.removeData('vfRequest'); });
        $panel.data('vfRequest', request);
    });
    if (window.acf) {
        acf.addAction('ready append', initialize);
        acf.addAction('open_field', initialize);
        acf.addAction('before_duplicate', function ($field) {
            $field.find('.vf-rule-setting textarea').each(removeEditor);
        });
        acf.addAction('after_duplicate', initialize);
    }
})(jQuery);
