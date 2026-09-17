import tippy from 'tippy.js';
import { __wprm } from '../shared/Translations';

let activeTooltip = null;

// These elements already have an action. Tooltip keyboard handling must not
// replace native activation or a handler on an enclosing link/control.
export const tooltipActionSelector = 'a[href], button, input, select, textarea, summary, [onclick]';

// Shared informational tooltips have explicit dismissal on touch and keyboard.
export default {
    fn( instance ) {
        const { reference, popper } = instance;
        const doc = reference.ownerDocument;
        const actionElement = reference.closest( tooltipActionSelector );
        const focusTarget = actionElement && reference.tabIndex < 0 ? actionElement : reference;
        const listeners = [];
        let pointerDown = false;
        let suppressHover = false;
        let scrollFrame = null;
        const originalAttributes = {};
        const setDismissible = enabled => {
            popper.querySelector( '.tippy-box' ).classList.toggle( 'wprm-tooltip-dismissible', enabled );
            // Centered image hints must not cover the image's own mouse action.
            popper.classList.toggle( 'wprm-tooltip-image-hover', reference.classList.contains( 'wprm-recipe-instruction-image-tooltip' ) && ! enabled );
            if ( instance.popperInstance ) {
                instance.popperInstance.update();
            }
        };

        const listen = ( target, type, handler ) => {
            target.addEventListener( type, handler );
            listeners.push( () => target.removeEventListener( type, handler ) );
        };
        const setAttribute = ( name, value ) => {
            originalAttributes[ name ] = focusTarget.getAttribute( name );
            focusTarget.setAttribute( name, value );
        };
        const contains = target => target && ( focusTarget.contains( target ) || popper.contains( target ) );
        const toggle = () => {
            pointerDown = false;
            instance.clearDelayTimeouts();
            if ( instance.state.isVisible ) {
                suppressHover = true;
                instance.hide();
            } else {
                suppressHover = false;
                instance.show();
            }
        };
        const close = event => {
            if ( event ) {
                event.stopPropagation();
            }
            // Focus before hiding so the focus handler cannot reopen the popup.
            focusTarget.focus( { preventScroll: true } );
            suppressHover = true;
            instance.hide();
        };
        const onScroll = event => {
            // A long definition may scroll independently of the page.
            if ( ! popper.contains( event.target ) ) {
                instance.hide();
            }
        };
        const onEscape = event => {
            if ( 'Escape' === event.key ) {
                event.preventDefault();
                if ( popper.contains( doc.activeElement ) ) {
                    close();
                } else {
                    suppressHover = true;
                    instance.hide();
                }
            }
        };
        const removeVisibleListeners = () => {
            doc.defaultView.cancelAnimationFrame( scrollFrame );
            doc.removeEventListener( 'scroll', onScroll, true );
            doc.removeEventListener( 'keydown', onEscape );
            if ( activeTooltip === instance ) {
                activeTooltip = null;
            }
        };

        return {
            onCreate() {
                const box = popper.querySelector( '.tippy-box' );
                box.classList.add( 'wprm-tooltip-popup' );
                const image = reference.querySelector( 'img[alt]' );
                box.setAttribute( 'aria-label', focusTarget.getAttribute( 'aria-label' ) || reference.textContent.trim() || ( image && image.alt ) || __wprm( 'More information' ) );

                // Keep controls outside .tippy-content, which setContent replaces.
                const button = doc.createElement( 'button' );
                button.type = 'button';
                button.className = 'wprm-tooltip-close';
                button.setAttribute( 'aria-label', __wprm( 'Close' ) );
                button.textContent = '\u00d7';
                box.insertBefore( button, box.firstChild );
                listen( button, 'click', close );

                if ( ! actionElement && ! reference.hasAttribute( 'tabindex' ) ) {
                    setAttribute( 'tabindex', '0' );
                }
                setAttribute( 'aria-haspopup', 'dialog' );
                setAttribute( 'aria-controls', popper.id );

                // Let click toggle exactly once, without a preceding focus opening it.
                listen( reference, 'pointerdown', event => {
                    pointerDown = true;
                    setDismissible( 'mouse' !== event.pointerType );
                } );
                listen( reference, 'pointercancel', () => { pointerDown = false; } );
                listen( reference, 'click', () => {
                    toggle();
                    if ( actionElement ) {
                        // Favorites can replace the active trigger after their
                        // delegated click handler runs. Do not leave its popup open.
                        doc.defaultView.requestAnimationFrame( () => {
                            if ( ! instance.state.isDestroyed && ! reference.getClientRects().length ) {
                                instance.hide();
                            }
                        } );
                    }
                } );
                listen( focusTarget, 'focus', () => {
                    if ( ! pointerDown ) {
                        setDismissible( true );
                        instance.show();
                    }
                    pointerDown = false;
                } );
                listen( focusTarget, 'keydown', event => {
                    if ( ! actionElement && event.target === reference && ( 'Enter' === event.key || ' ' === event.key ) ) {
                        event.preventDefault();
                        setDismissible( true );
                        if ( ! event.repeat ) {
                            toggle();
                        }
                    }
                    // The popup can be mounted outside the notes wrapper. Give
                    // keyboard users a direct path to its close button and links.
                    if ( 'Tab' === event.key && ! event.shiftKey && instance.state.isVisible ) {
                        event.preventDefault();
                        setDismissible( true );
                        button.focus();
                    }
                } );
                listen( button, 'keydown', event => {
                    if ( 'Tab' === event.key && event.shiftKey ) {
                        event.preventDefault();
                        focusTarget.focus( { preventScroll: true } );
                    }
                } );
                const onFocusOut = event => {
                    pointerDown = false;
                    if ( ! contains( event.relatedTarget ) ) {
                        instance.hide();
                    }
                };
                listen( focusTarget, 'focusout', onFocusOut );
                listen( popper, 'focusout', onFocusOut );

                listen( reference, 'mouseenter', () => {
                    if ( ! tippy.currentInput.isTouch && ! suppressHover ) {
                        // Mouse hover retains the compact tooltip presentation.
                        if ( ! contains( doc.activeElement ) ) {
                            setDismissible( false );
                        }
                        instance.clearDelayTimeouts();
                        instance.show();
                    }
                } );
                const onMouseLeave = event => {
                    if ( event.currentTarget === reference ) {
                        suppressHover = false;
                    }
                    if ( ! tippy.currentInput.isTouch && ! contains( doc.activeElement ) ) {
                        instance.hideWithInteractivity( event );
                    }
                };
                listen( reference, 'mouseleave', onMouseLeave );
                listen( popper, 'mouseleave', onMouseLeave );
            },
            onShow() {
                if ( activeTooltip && activeTooltip !== instance ) {
                    activeTooltip.hide();
                }
                activeTooltip = instance;
                // Focus and popup placement can scroll the page while opening.
                // Start listening after those opening events have been delivered.
                scrollFrame = doc.defaultView.requestAnimationFrame( () => {
                    doc.addEventListener( 'scroll', onScroll, { capture: true, passive: true } );
                } );
                doc.addEventListener( 'keydown', onEscape );
            },
            onClickOutside() {
                instance.hide();
            },
            onHide() {
                if ( popper.contains( doc.activeElement ) ) {
                    focusTarget.focus( { preventScroll: true } );
                }
                removeVisibleListeners();
            },
            onDestroy() {
                removeVisibleListeners();
                listeners.forEach( remove => remove() );
                Object.keys( originalAttributes ).forEach( name => {
                    const value = originalAttributes[ name ];
                    if ( null === value ) {
                        focusTarget.removeAttribute( name );
                    } else {
                        focusTarget.setAttribute( name, value );
                    }
                } );
            },
        };
    },
};
