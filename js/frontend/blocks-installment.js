(function(){
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement, useState, useEffect } = window.wp.element;
const { __ } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;

const settings = window.wc.wcSettings.getSetting( 'alipay_installment_data', {} );
const defaultLabel = __( '支付宝花呗分期', 'woo-alipay' );
const defaultDescription = __( '使用支付宝花呗分期付款，支持3期、6期、12期免息或低息分期。', 'woo-alipay' );

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

const Content = ( props ) => {
    // 检查是否满足最小金额要求
    const meetsMinAmount = settings.meetsMinAmount || false;
    const minAmount = settings.minAmount || 100;
    const cartTotal = settings.cartTotal || 0;
    const availablePeriods = settings.availablePeriods || ['3', '6', '12'];
    const defaultPeriod = settings.defaultPeriod || '3';

    const [ selectedPeriod, setSelectedPeriod ] = useState( String(defaultPeriod) );

    // 将选择的分期数传递到服务端（Store API -> payment_data）
    useEffect( () => {
        if ( ! props?.eventRegistration ) return;
        const unsubscribe = props.eventRegistration.onPaymentSetup( () => {
            return {
                type: 'success',
                paymentMethodData: {
                    alipay_installment_period: selectedPeriod,
                },
            };
        } );
        return () => {
            if ( typeof unsubscribe === 'function' ) unsubscribe();
        };
    }, [ selectedPeriod ] );
    
    // 如果不满足最小金额，显示提示
    if (!meetsMinAmount) {
        return createElement( 'div', {
            style: { 
                padding: '10px',
                background: '#fff3cd',
                border: '1px solid #ffc107',
                borderRadius: '4px',
                color: '#856404',
                marginTop: '10px'
            }
        }, __( '订单金额需满 ￥' + minAmount + ' 才能使用花呗分期', 'woo-alipay' ) );
    }
    
    return createElement( 'div', {
        style: { padding: '10px 0' }
    }, [
        createElement( 'p', { 
            key: 'description',
            style: { marginBottom: '15px' }
        }, decodeEntities( settings.description || defaultDescription ) ),
        createElement( 'p', {
            key: 'fee-note',
            style: { margin: '6px 0', fontSize: '12px', color: '#666' }
        }, settings.feePayer === 'seller' ? __( '免息分期（手续费由商家承担）', 'woo-alipay' ) : __( '可能产生分期手续费，以支付宝支付页面为准', 'woo-alipay' ) ),
        
        createElement( 'div', {
            key: 'period-info',
            style: {
                padding: '12px',
                background: '#f9f9f9',
                border: '1px solid #e0e0e0',
                borderRadius: '4px',
                marginTop: '10px'
            }
        }, [
            createElement( 'p', {
                key: 'title',
                style: { 
                    fontWeight: '600',
                    marginBottom: '8px',
                    fontSize: '14px'
                }
            }, __( '选择分期期数：', 'woo-alipay' ) ),
            
            createElement( 'div', {
                key: 'periods',
                style: {
                    margin: '0',
                    padding: '0',
                    display: 'grid',
                    gap: '6px'
                }
            }, availablePeriods.map(period => {
                const monthlyAmount = (cartTotal / parseInt(period)).toFixed(2);
                const id = 'alipay-installment-' + period;
                return createElement( 'label', {
                    key: period,
                    htmlFor: id,
                    style: { display: 'flex', alignItems: 'center', gap: '8px', fontSize: '13px' }
                }, [
                    createElement( 'input', {
                        id,
                        type: 'radio',
                        name: 'alipay_installment_period',
                        value: String(period),
                        checked: String(selectedPeriod) === String(period),
                        onChange: () => setSelectedPeriod(String(period))
                    } ),
                    createElement( 'span', { key: 'label-' + period }, [
                        period + ' 期（每期 ￥' + monthlyAmount,
                        (settings.feePayer === 'seller' && settings.showInterestFreeBadge) ? createElement('span', { key: 'free-'+period, className: 'alipay-badge alipay-badge--free', style: { marginLeft: '4px' } }, __('免息', 'woo-alipay')) : null,
                        '）'
                    ] )
                ] );
            }) )
        ]),
        
        createElement( 'p', {
            key: 'note',
            style: {
                marginTop: '10px',
                fontSize: '12px',
                color: '#666'
            }
        }, __( '在支付页面将可以选择具体的分期期数', 'woo-alipay' ) )
    ]);
};

const alipayInstallmentPaymentMethod = {
    name: 'alipay_installment',
    paymentMethodId: 'alipay_installment',
    label: createElement( Label ),
    content: createElement( Content ),
    edit: createElement( Content ),
    canMakePayment: () => {
        const behavior = settings.insufficientBehavior || 'hide';
        const meets = !!settings.meetsMinAmount;
        if ( behavior === 'hide' && !meets ) return false;
        return true;
    },
    ariaLabel: decodeEntities( settings.title || defaultLabel ),
    supports: {
        features: settings?.supports ?? ['products'],
    },
};

registerPaymentMethod( alipayInstallmentPaymentMethod );
})();
