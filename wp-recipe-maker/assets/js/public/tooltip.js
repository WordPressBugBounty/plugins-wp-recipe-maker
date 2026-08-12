import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';

import '../../css/public/tooltip.scss';

window.WPRecipeMaker = typeof window.WPRecipeMaker === "undefined" ? {} : window.WPRecipeMaker;

const fadedNotesSelector = [
    '.wprm-recipe-ingredient-notes-faded',
    '.wprm-recipe-ingredient-notes-smaller-faded',
    '.wprm-recipe-equipment-notes-faded',
    '.wprm-recipe-equipment-notes-smaller-faded',
].join( ', ' );

window.WPRecipeMaker.tooltip = {
	init() {
		WPRecipeMaker.tooltip.addTooltips();
	},
	addTooltips( root = document ) {
        let containers = [];

        if ( root && root.classList && root.classList.contains( 'wprm-tooltip' ) ) {
            containers.push( root );
        }

        if ( root && 'function' === typeof root.querySelectorAll ) {
            containers = containers.concat( [ ...root.querySelectorAll( '.wprm-tooltip' ) ] );
        }

        for ( let container of containers ) {
            // Remove any existing tippy.
            const existingTippy = container._tippy;

            if ( existingTippy ) {
                existingTippy.destroy();
            }

            // Check for tooltip.
            const tooltip = container.dataset.hasOwnProperty( 'tooltip' ) ? container.dataset.tooltip : false;

            if ( tooltip ) {
                // Preserve the native semantics of links and form controls.
                if ( ! container.matches( 'a[href], button, input, select, textarea, summary' ) ) {
                    container.role = "button";
                }

                const hasHtml = container.dataset.hasOwnProperty( 'tooltipHtml' ) && '1' === container.dataset.tooltipHtml;
                const isInstructionImage = container.classList.contains( 'wprm-recipe-instruction-image-tooltip' );
                const instructionImage = isInstructionImage ? container.querySelector( 'img' ) : false;

                let content = tooltip;
                if ( hasHtml ) {
                    // Strip HTML tags.
                    content = content.replace( /<[^>]*>/g, '' );
                }

                // Keep the tooltip outside faded notes so their opacity does not affect it.
                const fadedNotes = container.closest( fadedNotesSelector );

                tippy( container, {
                    theme: 'wprm',
                    content,
                    allowHTML: false,
                    interactive: true,
                    ...( instructionImage ? {
                        arrow: false,
                        placement: 'top',
                        getReferenceClientRect: () => {
                            const imageRect = instructionImage.getBoundingClientRect();
                            const x = imageRect.left + imageRect.width / 2;
                            const y = imageRect.top + imageRect.height / 2;

                            return {
                                width: 0,
                                height: 0,
                                top: y,
                                right: x,
                                bottom: y,
                                left: x,
                            };
                        },
                        offset: ( { popper } ) => [ 0, - popper.height / 2 ],
                    } : {} ),
                    ...( fadedNotes ? {
                        appendTo: () => fadedNotes.parentElement || document.body,
                    } : {} ),
                    onCreate(instance) {
                        // Prevents the tooltip from breaking ingredients into multiple lines.
                        instance.popper.style.display = 'inline-block';

                        // State of fetching.
                        instance._isFetching = false;
                        instance._fetchedContent = false;
                    },
                    onShow(instance) {
                        if ( instance._isFetching || instance._fetchedContent ) {
                            return;
                        }

                        if ( hasHtml ) {
                            instance._isFetching = true;

                            fetch( `${wprm_public.endpoints.utilities}/sanitize`, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                },
                                credentials: 'same-origin',
                                body: JSON.stringify( { text: tooltip } ),
                            } ).then( ( response ) => {
                                if ( response.ok ) {
                                    return response.json();
                                } else {
                                    return false;
                                }
                            } ).then( ( html ) => {
                                instance._isFetching = false;
                                instance._fetchedContent = true;

                                if ( html ) {
                                    instance.setContent( html );

                                    // Change allowHTML to true to show HTML.
                                    instance.setProps( { allowHTML: true } );
                                }
                            } );
                        }
                    }
                });
            }
        }
    },
};

ready(() => {
	window.WPRecipeMaker.tooltip.init();
});

function ready( fn ) {
    if (document.readyState != 'loading'){
        fn();
    } else {
        document.addEventListener('DOMContentLoaded', fn);
    }
}
