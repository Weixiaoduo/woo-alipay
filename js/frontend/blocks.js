const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement } = window.wp.element;
const { __ } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;

const settings = window.wc.wcSettings.getSetting( 'alipay_data', {} );
const defaultLabel = __( '支付宝', 'woo-alipay' );
const defaultDescription = __( '通过支付宝付款（中国大陆，包括香港和澳门）。', 'woo-alipay' );

const Label = ( props ) => {
    const { PaymentMethodLabel } = props.components;
    const iconElement = settings.icon ? createElement( 'img', {
        src: settings.icon,
        alt: decodeEntities( settings.title || defaultLabel ),
        style: { 
            width: '24px', 
            height: '24px', 
            marginRight: '8px',
            verticalAlign: 'middle'
        }
    } ) : null;
    
    return createElement( 'div', {
        style: { display: 'flex', alignItems: 'center' }
    }, [
        iconElement,
        createElement( PaymentMethodLabel, { 
            text: decodeEntities( settings.title || defaultLabel ),
            key: 'label'
        } )
    ] );
};

const Content = () => {
    return createElement( 'div', {
        style: { padding: '10px 0' }
    }, decodeEntities( settings.description || defaultDescription ) );
};

const alipayPaymentMethod = {
    name: 'alipay',
    label: createElement( Label ),
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: decodeEntities( settings.title || defaultLabel ),
    supports: {
        features: settings?.supports ?? ['products'],
    },
};

registerPaymentMethod( alipayPaymentMethod );