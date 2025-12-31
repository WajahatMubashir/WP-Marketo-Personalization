(function (wp) {
    var registerBlockType = wp.blocks.registerBlockType;
    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var TabPanel = wp.components.TabPanel;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;

    var SEGMENTS =
        (window.mpSegments && Array.isArray(window.mpSegments) && window.mpSegments.length)
            ? window.mpSegments
            : (window.tcpMpSegments && Array.isArray(window.tcpMpSegments) && window.tcpMpSegments.length)
            ? window.tcpMpSegments
            : [{ key: 'default', label: 'Default' }];

    var DEFAULT_CONTENT = SEGMENTS.reduce(function (acc, seg) {
        acc[seg.key] = {};
        return acc;
    }, {});

    function normalizeContent(content) {
        var incoming = content && typeof content === 'object' ? content : {};
        var output = {};

        SEGMENTS.forEach(function (segment) {
            output[segment.key] = Object.assign({}, incoming[segment.key]);
        });

        return output;
    }

    function Edit(props) {
        var content = normalizeContent(props.attributes.content);

        function updateSegment(segment, key, value) {
            var next = Object.assign({}, DEFAULT_CONTENT, content);
            next[segment] = Object.assign({}, content[segment], {
                [key]: value,
            });

            props.setAttributes({ content: next });
        }

        return el(
            TabPanel,
            {
                className: 'mp-dynamic-tabs',
                tabs: SEGMENTS.map(function (segment) {
                    return {
                        name: segment.key,
                        title: segment.label,
                    };
                }),
            },
            function (tab) {
                var segmentKey = tab.name;
                var data = content[segmentKey] || {};

                return el(
                    Fragment,
                    null,
                    el(TextControl, {
                        label: 'Headline',
                        value: data.headline || '',
                        onChange: function (value) {
                            updateSegment(segmentKey, 'headline', value);
                        },
                    }),
                    el(TextareaControl, {
                        label: 'Body',
                        value: data.body || '',
                        onChange: function (value) {
                            updateSegment(segmentKey, 'body', value);
                        },
                    }),
                    el(TextControl, {
                        label: 'Button Text',
                        value: data.buttonText || '',
                        onChange: function (value) {
                            updateSegment(segmentKey, 'buttonText', value);
                        },
                    }),
                    el(TextControl, {
                        label: 'Button URL',
                        value: data.buttonUrl || '',
                        onChange: function (value) {
                            updateSegment(segmentKey, 'buttonUrl', value);
                        },
                    })
                );
            }
        );
    }

    registerBlockType('tcp/dynamic-content', {
        title: 'Dynamic Content (Marketo Segment)',
        description:
            'Segmented industry content that swaps copy based on Marketo Web Segment.',
        icon: 'admin-site',
        category: 'widgets',
        attributes: {
            content: {
                type: 'object',
                default: DEFAULT_CONTENT,
            },
        },
        supports: {
            html: false,
        },
        edit: Edit,
        save: window.tcpDynamicContentSave,
    });
})(window.wp);
