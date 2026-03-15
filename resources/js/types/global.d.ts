import type Echo from 'laravel-echo'
import type { Auth } from '@/types/auth'

declare global {
    interface Window {
        Pusher: unknown
        Echo: Echo
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string
            auth: Auth
            sidebarOpen: boolean
            [key: string]: unknown
        }
    }
}
