import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';
import type { NotificationsShared } from '@/types/notifications';
import type { RunningTimer } from '@/types/time';
import type { WorkspaceNav } from '@/types/workspace';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            workspaceNav: WorkspaceNav | null;
            runningTimer: RunningTimer | null;
            notifications: NotificationsShared | null;
            /** E-90: set while a super admin views the app as this person. */
            viewAs: {
                name: string;
                expires_at: string | null;
                reason: string | null;
            } | null;
            /** E-99: DPO contact for the footer; the notice to acknowledge, if any. */
            privacy: {
                dpo: {
                    name: string | null;
                    email: string | null;
                    phone: string | null;
                } | null;
                notice: { version: number; title: string; body: string } | null;
            } | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
