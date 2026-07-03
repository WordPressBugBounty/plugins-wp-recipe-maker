const splitLines = ( value ) => {
    const normalized = 'string' === typeof value ? value.replace( /\r\n/g, '\n' ).replace( /\r/g, '\n' ) : '';

    return normalized.split( '\n' );
};

const buildFallbackDiff = ( beforeLines, afterLines ) => {
    let prefix = 0;
    while ( prefix < beforeLines.length && prefix < afterLines.length && beforeLines[ prefix ] === afterLines[ prefix ] ) {
        prefix++;
    }

    let beforeSuffix = beforeLines.length - 1;
    let afterSuffix = afterLines.length - 1;
    while ( beforeSuffix >= prefix && afterSuffix >= prefix && beforeLines[ beforeSuffix ] === afterLines[ afterSuffix ] ) {
        beforeSuffix--;
        afterSuffix--;
    }

    return {
        before: beforeLines.map( ( line, index ) => ( {
            line,
            changed: index >= prefix && index <= beforeSuffix,
        } ) ),
        after: afterLines.map( ( line, index ) => ( {
            line,
            changed: index >= prefix && index <= afterSuffix,
        } ) ),
    };
};

const buildLcsDiff = ( beforeLines, afterLines ) => {
    const beforeLength = beforeLines.length;
    const afterLength = afterLines.length;
    const table = Array.from( { length: beforeLength + 1 }, () => Array( afterLength + 1 ).fill( 0 ) );

    for ( let beforeIndex = beforeLength - 1; beforeIndex >= 0; beforeIndex-- ) {
        for ( let afterIndex = afterLength - 1; afterIndex >= 0; afterIndex-- ) {
            if ( beforeLines[ beforeIndex ] === afterLines[ afterIndex ] ) {
                table[ beforeIndex ][ afterIndex ] = table[ beforeIndex + 1 ][ afterIndex + 1 ] + 1;
            } else {
                table[ beforeIndex ][ afterIndex ] = Math.max( table[ beforeIndex + 1 ][ afterIndex ], table[ beforeIndex ][ afterIndex + 1 ] );
            }
        }
    }

    const beforeChanged = Array( beforeLength ).fill( true );
    const afterChanged = Array( afterLength ).fill( true );
    let beforeIndex = 0;
    let afterIndex = 0;

    while ( beforeIndex < beforeLength && afterIndex < afterLength ) {
        if ( beforeLines[ beforeIndex ] === afterLines[ afterIndex ] ) {
            beforeChanged[ beforeIndex ] = false;
            afterChanged[ afterIndex ] = false;
            beforeIndex++;
            afterIndex++;
        } else if ( table[ beforeIndex + 1 ][ afterIndex ] >= table[ beforeIndex ][ afterIndex + 1 ] ) {
            beforeIndex++;
        } else {
            afterIndex++;
        }
    }

    return {
        before: beforeLines.map( ( line, index ) => ( {
            line,
            changed: beforeChanged[ index ],
        } ) ),
        after: afterLines.map( ( line, index ) => ( {
            line,
            changed: afterChanged[ index ],
        } ) ),
    };
};

export const getLineDiff = ( before, after ) => {
    const beforeLines = splitLines( before );
    const afterLines = splitLines( after );
    const comparisonSize = beforeLines.length * afterLines.length;

    if ( comparisonSize > 250000 ) {
        return buildFallbackDiff( beforeLines, afterLines );
    }

    return buildLcsDiff( beforeLines, afterLines );
};
