(function (wp) {
    var el = wp.element.createElement;

    function Save(props) {
        var content = props.attributes.content || {};

        return el(
            'div',
            { className: 'mp-dynamic' },
            Object.entries(content).map(function (_ref) {
                var segment = _ref[0];
                var data = _ref[1] || {};

                var className = [
                    'mp-seg',
                    'mp-' + segment,
                    segment === 'default' ? 'is-active' : '',
                ]
                    .filter(Boolean)
                    .join(' ');

                return el(
                    'div',
                    { className: className, 'data-segment': segment, key: segment },
                    [
                        data.headline
                            ? el('h2', null, data.headline)
                            : null,
                        data.body ? el('p', null, data.body) : null,
                        data.buttonText
                            ? el(
                                  'a',
                                  {
                                      href: data.buttonUrl || '#',
                                      className: 'btn',
                                  },
                                  data.buttonText
                              )
                            : null,
                    ]
                );
            })
        );
    }

    window.tcpDynamicContentSave = Save;
})(window.wp);
