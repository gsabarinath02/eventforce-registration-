/* eslint-disable lingui/no-unlocalized-strings */
(function () {
    const isScriptLoaded = () => !!window.eventforceWidgetLoaded;

    const loadWidget = () => {
        window.eventforceWidgetLoaded = true;

        let scriptOrigin;
        try {
            const scriptURL = document.currentScript.src;
            scriptOrigin = new URL(scriptURL).origin;
        } catch (e) {
            console.error('Eventforce widget error: Invalid script URL');
            return;
        }

        const widgets = document.querySelectorAll('.eventforce-widget');
        widgets.forEach((widget, index) => {
            const eventId = widget.getAttribute('data-eventforce-id');
            if (!eventId) {
                console.error('Eventforce widget error: data-eventforce-id is required');
                return;
            }

            const iframe = document.createElement('iframe');
            iframe.setAttribute('sandbox', '' +
                'allow-forms' +
                ' allow-scripts' +
                ' allow-same-origin' +
                ' allow-popups' +
                ' allow-popups-to-escape-sandbox' +
                ' allow-top-navigation' +
                ' allow-top-navigation-by-user-activation' +
                ' allow-downloads' +
                ' allow-modals' +
                ' allow-orientation-lock' +
                ' allow-pointer-lock' +
                ' allow-popups-to-escape-sandbox' +
                ' allow-presentation'
            );

            iframe.setAttribute('title', 'Event Widget');
            iframe.style.border = 'none';
            iframe.style.width = '100%';

            const iframeId = `eventforce-iframe-${index}`;
            iframe.id = iframeId;

            let src = `${scriptOrigin}/widget/${encodeURIComponent(eventId)}?iframeId=${iframeId}&`;
            const params = [];
            Array.from(widget.attributes).forEach(attr => {
                if (attr.name.startsWith('data-eventforce-') && attr.name !== 'data-eventforce-id') {
                    const paramName = attr.name.substring(13).replace(/-([a-z])/g, (g) => g[1].toUpperCase());
                    params.push(`${paramName}=${encodeURIComponent(attr.value)}`);
                }
            });

            iframe.src = src + params.join('&');

            widget.appendChild(iframe);

            const autoResize = widget.getAttribute('data-eventforce-autoresize') !== 'false';

            if (autoResize) {
                window.addEventListener('message', (event) => {
                    if (event.origin !== scriptOrigin) {
                        return;
                    }

                    const { type, height, iframeId: messageIframeId } = event.data;
                    if (type === 'resize' && height && messageIframeId === iframeId) {
                        const targetIframe = document.getElementById(messageIframeId);
                        if (targetIframe) {
                            targetIframe.style.height = `${height}px`;
                        }
                    }
                });
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadWidget);
    } else {
        if (!isScriptLoaded()) {
            loadWidget();
        }
    }
})();
