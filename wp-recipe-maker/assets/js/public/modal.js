import MicroModal from 'micromodal';
import '../../css/public/modal.scss';

window.WPRecipeMaker = typeof window.WPRecipeMaker === "undefined" ? {} : window.WPRecipeMaker;

window.WPRecipeMaker.modal = {
    savedScrollPosition: 0,
    currentOpenUid: null,
    htmlMarginTopValue: null,
    closeAnimations: new WeakMap(),

    cancelCloseAnimation( modal ) {
        const onAnimationEnd = this.closeAnimations.get( modal );
        if ( onAnimationEnd ) {
            modal.removeEventListener( 'animationend', onAnimationEnd );
            this.closeAnimations.delete( modal );
        }
        modal.classList.remove( 'is-closing' );
    },

    awaitCloseAnimation( modal ) {
        this.cancelCloseAnimation( modal );
        // Keep the modal visible while MicroModal removes is-open. Own the
        // listener so reopening can cancel it before the next animation ends.
        modal.classList.add( 'is-closing' );
        const onAnimationEnd = event => {
            if ( event.animationName === 'wprmPopupModalFadeOut' && event.target.classList.contains( 'wprm-popup-modal__overlay' ) ) {
                this.cancelCloseAnimation( modal );
            }
        };
        this.closeAnimations.set( modal, onAnimationEnd );
        modal.addEventListener( 'animationend', onAnimationEnd );
    },

    getHtmlMarginTopValue() {
        if ( null !== this.htmlMarginTopValue ) {
            return this.htmlMarginTopValue;
        }

        // Check if html has a margin-top (e.g., from WordPress admin bar).
        const htmlStyle = window.getComputedStyle( document.documentElement );
        const htmlMarginTop = htmlStyle.marginTop;
        const parsedMarginTop = htmlMarginTop && htmlMarginTop !== '0px' ? parseFloat( htmlMarginTop ) : 0;

        this.htmlMarginTopValue = isNaN( parsedMarginTop ) ? 0 : parsedMarginTop;
        return this.htmlMarginTopValue;
    },

    resetHtmlMarginTopValue() {
        this.htmlMarginTopValue = null;
    },
    
    open( uid, data = {} ) {
        // Close currently open modal if there is one
        if ( this.currentOpenUid && this.currentOpenUid !== uid ) {
            this.close( this.currentOpenUid );
        }

        const modalId = `wprm-popup-modal-${ uid }`;
        const modalElement = document.getElementById( modalId );
        if ( ! modalElement ) {
            console.warn( `WPRM modal "${ modalId }" could not be found in the DOM.` );
            return;
        }

        this.cancelCloseAnimation( modalElement );

        // Prevent body scroll by fixing it in place
        this.savedScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
        const htmlMarginTopValue = this.getHtmlMarginTopValue();
        
        document.body.classList.add( 'wprm-popup-modal-open' );
        // Adjust body top position to account for html margin-top
        document.body.style.top = `-${this.savedScrollPosition - htmlMarginTopValue}px`;
        
        MicroModal.show( modalId, {
            onShow: modal => {
                // Track this as the currently open modal
                this.currentOpenUid = uid;
                const type = modal.dataset.type;
                document.dispatchEvent( new CustomEvent( 'wprm-modal-open', { detail: { type, uid, modal, data } } ) );
            },
            onClose: modal => {
                this.awaitCloseAnimation( modal );
                // Clear the currently open modal tracking
                if ( this.currentOpenUid === uid ) {
                    this.currentOpenUid = null;
                }
                const type = modal.dataset.type;
                document.dispatchEvent( new CustomEvent( 'wprm-modal-close', { detail: { type, uid, modal, data } } ) );
                
                // Re-enable body scroll and restore scroll position
                const scrollPosition = this.savedScrollPosition;
                document.body.classList.remove( 'wprm-popup-modal-open' );
                document.body.style.top = '';
                this.savedScrollPosition = 0;
                
                // Restore scroll position with instant behavior
                window.scrollTo( {
                    top: scrollPosition,
                    behavior: 'instant'
                } );
            },
            awaitCloseAnimation: false,
        });
    },
    close( uid ) {
        // Ignore duplicate close requests for modals that are no longer open.
        if ( `${ this.currentOpenUid }` !== `${ uid }` ) {
            return;
        }

        MicroModal.close('wprm-popup-modal-' + uid);
        // Clear the currently open modal tracking if this was the open one
        if ( this.currentOpenUid === uid ) {
            this.currentOpenUid = null;
        }
    },
};

window.addEventListener( 'resize', () => window.WPRecipeMaker.modal.resetHtmlMarginTopValue() );
window.addEventListener( 'orientationchange', () => window.WPRecipeMaker.modal.resetHtmlMarginTopValue() );
