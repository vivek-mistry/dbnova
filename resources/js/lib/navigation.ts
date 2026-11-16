import {
    Activity,
    ArrowDownUp,
    Database,
    HeartPulse,
    ScrollText,
    LayoutGrid,
    SquareTerminal,
} from '@lucide/vue';
import { dashboard } from '@/routes';
import { index as auditIndex } from '@/routes/audit';
import { index as databasesIndex } from '@/routes/databases';
import { index as importExportIndex } from '@/routes/import-export';
import { index as processesIndex } from '@/routes/processes';
import { index as sqlIndex } from '@/routes/sql';
import { index as systemIndex } from '@/routes/system';
import type { NavItem } from '@/types';

export const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Databases',
        href: databasesIndex(),
        icon: Database,
    },
    {
        title: 'SQL Editor',
        href: sqlIndex(),
        icon: SquareTerminal,
    },
    {
        title: 'Import / Export',
        href: importExportIndex(),
        icon: ArrowDownUp,
    },
    {
        title: 'Processes',
        href: processesIndex(),
        icon: Activity,
    },
    {
        title: 'Audit log',
        href: auditIndex(),
        icon: ScrollText,
    },
    {
        title: 'System',
        href: systemIndex(),
        icon: HeartPulse,
    },
];
