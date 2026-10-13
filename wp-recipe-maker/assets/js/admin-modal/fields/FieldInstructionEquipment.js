import React from 'react';
import Select from 'react-select';
import he from 'he';

import { __wprm } from 'Shared/Translations';

const plainText = ( value ) => he.decode( String( value || '' ).replace( /<[^>]*>/g, '' ) );

const FieldInstructionEquipment = ( props ) => {
    const equipment = Array.isArray( props.equipment ) ? props.equipment : [];
    const allEquipment = Array.isArray( props.allEquipment ) ? props.allEquipment : [];
    const labels = allEquipment.map( item => [ plainText( item.amount ), plainText( item.name ) ].filter( Boolean ).join( ' ' ) );
    const options = allEquipment.map( ( item, index ) => ({
        value: item.uid,
        label: labels[ index ] + ( labels.indexOf( labels[ index ] ) !== labels.lastIndexOf( labels[ index ] ) ? ` (${ index + 1 })` : '' ),
    }) );
    const selected = equipment.map( uid => options.find( option => option.value === Number( uid ) ) ).filter( Boolean );

    return (
        <div className="wprm-admin-modal-field-instruction-after-container-ingredient">
            <Select
                isMulti
                inputId={ `wprm-instruction-equipment-${ props.uid }` }
                aria-label={ __wprm( 'Associated Equipment' ) }
                options={ options }
                value={ selected }
                placeholder={ __wprm( 'Select equipment...' ) }
                noOptionsMessage={ () => __wprm( allEquipment.length ? 'No equipment found.' : 'Add equipment to this recipe first.' ) }
                onChange={ ( value ) => props.onChangeEquipment( value ? value.map( option => option.value ) : [] ) }
                styles={{
                    container: provided => ({ ...provided, width: '100%', maxWidth: '100%' }),
                    control: provided => ({ ...provided, backgroundColor: 'white' }),
                }}
            />
        </div>
    );
};

export default FieldInstructionEquipment;
