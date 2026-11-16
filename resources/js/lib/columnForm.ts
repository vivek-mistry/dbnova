import type { ColumnForm, ColumnInfo, ColumnPayload } from '@/types';

export const NUMERIC_TYPES = [
    'tinyint',
    'smallint',
    'mediumint',
    'int',
    'bigint',
    'decimal',
    'float',
    'double',
];
export const INTEGER_TYPES = [
    'tinyint',
    'smallint',
    'mediumint',
    'int',
    'bigint',
];
export const TEXT_TYPES = [
    'char',
    'varchar',
    'tinytext',
    'text',
    'mediumtext',
    'longtext',
    'enum',
    'set',
];
export const TIMESTAMP_TYPES = ['datetime', 'timestamp'];
export const LENGTH_TYPES = [
    'char',
    'varchar',
    'binary',
    'varbinary',
    'bit',
    'decimal',
    'float',
    'double',
    'datetime',
    'timestamp',
    'time',
];

export function emptyColumn(overrides: Partial<ColumnForm> = {}): ColumnForm {
    return {
        name: '',
        type: 'varchar',
        length: '255',
        valuesText: '',
        unsigned: false,
        nullable: true,
        defaultKind: 'none',
        defaultValue: '',
        autoIncrement: false,
        onUpdateCurrentTimestamp: false,
        collation: '',
        comment: '',
        ...overrides,
    };
}

/** Parse ENUM/SET values from a column type such as "enum('a','it''s')". */
function enumValues(columnType: string): string[] {
    return [...columnType.matchAll(/'((?:[^']|'')*)'/g)].map((match) =>
        (match[1] ?? '').replaceAll("''", "'"),
    );
}

/** Build an editable form from an existing column. */
export function columnFromInfo(column: ColumnInfo): ColumnForm {
    const type = column.dataType;
    const isList = type === 'enum' || type === 'set';
    const length = isList
        ? ''
        : (/^\w+\(([^)]*)\)/.exec(column.columnType)?.[1] ?? '');
    const extra = column.extra.toLowerCase();
    const isCurrentTimestamp = (column.default ?? '')
        .toUpperCase()
        .startsWith('CURRENT_TIMESTAMP');

    return emptyColumn({
        name: column.name,
        type,
        length: INTEGER_TYPES.includes(type) ? '' : length,
        valuesText: isList ? enumValues(column.columnType).join('\n') : '',
        unsigned: column.columnType.includes(' unsigned'),
        nullable: column.nullable,
        defaultKind: isCurrentTimestamp
            ? 'current_timestamp'
            : column.default !== null
              ? 'value'
              : column.nullable && !extra.includes('auto_increment')
                ? 'null'
                : 'none',
        defaultValue: isCurrentTimestamp ? '' : (column.default ?? ''),
        autoIncrement: extra.includes('auto_increment'),
        onUpdateCurrentTimestamp: extra.includes('on update current_timestamp'),
        collation: column.collation ?? '',
        comment: column.comment,
    });
}

export function columnPayload(form: ColumnForm): ColumnPayload {
    return {
        name: form.name,
        type: form.type,
        length:
            LENGTH_TYPES.includes(form.type) && form.length !== ''
                ? form.length
                : null,
        values: form.valuesText
            .split('\n')
            .map((value) => value.trim())
            .filter((value) => value !== ''),
        unsigned: NUMERIC_TYPES.includes(form.type) && form.unsigned,
        nullable: form.nullable,
        default: {
            kind: form.defaultKind,
            value: form.defaultKind === 'value' ? form.defaultValue : null,
        },
        autoIncrement: INTEGER_TYPES.includes(form.type) && form.autoIncrement,
        onUpdateCurrentTimestamp:
            TIMESTAMP_TYPES.includes(form.type) &&
            form.onUpdateCurrentTimestamp,
        collation:
            TEXT_TYPES.includes(form.type) && form.collation !== ''
                ? form.collation
                : null,
        comment: form.comment,
    };
}
