(function(){
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement } = window.wp.element;
const { __ } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;

const settings = window.wc.wcSettings.getSetting( 'alipay_facetopay_data', {} );
const defaultLabel = __( '支付宝扫码支付', 'woo-alipay' );
const defaultDescription = __( '使用支付宝扫描二维码完成支付', 'woo-alipay' );

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
    }, [
        createElement( 'p', { 
            key: 'description',
            style: { marginBottom: '8px' }
        }, decodeEntities( settings.description || defaultDescription ) ),
        
        createElement( 'div', {
            key: 'qr-info',
            style: {
                padding: '10px',
                background: '#f0f7ff',
                border: '1px solid #d6e4ff',
                borderRadius: '4px',
                fontSize: '13px',
                color: '#096dd9'
            }
        }, [
            createElement( 'p', {
                key: 'tip1',
                style: { margin: '4px 0' }
            }, __( '点击下单后会显示支付二维码', 'woo-alipay' ) ),
            
            createElement( 'p', {
                key: 'tip2',
                style: { margin: '4px 0' }
            }, __( '请使用支付宝APP扫描二维码完成支付', 'woo-alipay' ) )
        ])
    ]);
};

const alipayFaceToPayPaymentMethod = {
    name: 'alipay_facetopay',
    label: createElement( Label ),
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => true,
    ariaLabel: decodeEntities( settings.title || defaultLabel ),
    supports: {
        features: settings?.supports ?? ['products'],
    },
};

registerPaymentMethod( alipayFaceToPayPaymentMethod );
})();
