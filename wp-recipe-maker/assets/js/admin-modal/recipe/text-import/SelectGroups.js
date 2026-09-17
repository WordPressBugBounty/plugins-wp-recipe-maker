import React from 'react';
import { __wprm } from 'Shared/Translations';
 
const SelectGroups = (props) => {
    const isIngredients = 'ingredients' === props.field;

    return (
        <div className="wprm-admin-modal-field-text-import-groups">
            <p>
                <strong>{ __wprm( 'Every non-empty line will be imported.' ) }</strong>{ ' ' }
                { __wprm( 'Check a box only to mark that line as a group heading. Unchecked lines are regular entries.' ) }
            </p>
            {
                props.value.map((field, index) => (
                    <div className="wprm-admin-modal-field-text-import-groups-field" key={index}>
                        <input
                            type="checkbox"
                            checked={ field.group }
                            aria-label={ `${ __wprm( 'Mark as group heading' ) }: ${ field.text }` }
                            onChange={(e) => {
                                let newFields = JSON.parse( JSON.stringify( props.value ) );
                                newFields[ index ].group = e.target.checked;
                                props.onChange(newFields);
                            } }
                        />
                        <input
                            type="text"
                            value={ field.text }
                            aria-label={ isIngredients ? __wprm( 'Ingredient or group heading' ) : __wprm( 'Instruction or group heading' ) }
                            style={ field.group ? { fontWeight: 'bold' } : null }
                            onChange={(e) => {
                                let newFields = JSON.parse( JSON.stringify( props.value ) );
                                newFields[ index ].text = e.target.value;
                                props.onChange(newFields);
                            } }
                        />
                    </div>
                ))
            }
        </div>
    );
}
export default SelectGroups;
