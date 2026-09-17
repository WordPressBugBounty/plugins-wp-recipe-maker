import React from 'react';
import {
    Accordion,
    AccordionItem,
    AccordionItemHeading,
    AccordionItemButton,
    AccordionItemPanel,
} from 'react-accessible-accordion';

import '../../../css/admin/onboarding/accordion.scss';

const SimpleAccordion = (props) => {
    const itemIds = props.items.map( ( item, index ) => item.id || index );
    const preExpanded = itemIds.includes( props.preExpanded ) ? [ props.preExpanded ] : [];

    return (
        <div className="wprm-admin-onboarding-accordion-container">
            {
                props.hasOwnProperty( 'title' )
                &&
                <h2>
                    { props.title }
                </h2>
            }
            <Accordion
                className="wprm-admin-onboarding-accordion"
                allowZeroExpanded={ true }
                preExpanded={ preExpanded }
            >
                {
                    props.items.map((item, index) => (
                        <AccordionItem
                            key={ item.id || index }
                            uuid={ item.id || index }
                        >
                            <AccordionItemHeading>
                                <AccordionItemButton>
                                    { item.header }
                                </AccordionItemButton>
                            </AccordionItemHeading>
                            <AccordionItemPanel>
                                { item.content }
                            </AccordionItemPanel>
                        </AccordionItem>
                    ))
                }
            </Accordion>
        </div>
    );
}
export default SimpleAccordion;
