<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
    DropdownMenuItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { Bell } from 'lucide-vue-next';
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useEchoNotifications } from '@/composables/useEchoNotifications';

interface Notification {
    id: string;
    type: string;
    message: string;
    read_at: string | null;
    created_at: string;
    data: {
        type: string;
        website_name?: string;
        order_id?: number;
        submission_id?: number;
        [key: string]: any;
    };
}

const page = usePage();
const notifications = ref<Notification[]>([]);
const loading = ref(false);
const localUnreadCount = ref(0);

// Compute unread count from both props and local state
const unreadCount = computed(() => {
    try {
        const propCount = (page.props as any).notifications?.unread_count ?? 0;
        // Use local count if it's higher (for real-time updates)
        return Math.max(propCount, localUnreadCount.value);
    } catch {
        return localUnreadCount.value;
    }
});

const formatTimeAgo = (dateString: string): string => {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now.getTime() - date.getTime()) / 1000);

    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
    
    return date.toLocaleDateString();
};

const fetchNotifications = async () => {
    if (loading.value) return;
    
    // Don't fetch if user is not authenticated
    if (!page.props.auth?.user) {
        return;
    }
    
    loading.value = true;
    try {
        const response = await fetch('/notifications');
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        const data = await response.json();
        notifications.value = data.notifications || [];
        // Update local unread count
        localUnreadCount.value = data.unread_count ?? 0;
    } catch (error) {
        console.error('Failed to fetch notifications:', error);
        notifications.value = [];
    } finally {
        loading.value = false;
    }
};

const handleNotificationClick = async (notification: Notification) => {
    try {
        const response = await fetch(`/notifications/${notification.id}/read`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
        });
        
        const data = await response.json();
        
        // Update notification as read locally
        const index = notifications.value.findIndex(n => n.id === notification.id);
        if (index !== -1) {
            notifications.value[index].read_at = new Date().toISOString();
            // Decrease unread count if it was unread
            if (!notification.read_at) {
                localUnreadCount.value = Math.max(0, localUnreadCount.value - 1);
            }
        }
        
        if (data.success && data.redirect_url) {
            // Navigate to the notification's target page
            router.visit(data.redirect_url);
        } else {
            // Fallback to client-side URL construction
            const url = getNotificationUrl(notification);
            if (url !== '#') {
                router.visit(url);
            }
        }
        
        // Refresh notifications and unread count
        await fetchNotifications();
        router.reload({ only: ['notifications'] });
    } catch (error) {
        console.error('Failed to mark notification as read:', error);
    }
};

const markAllAsRead = async () => {
    try {
        const response = await fetch('/notifications/read-all', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Mark all as read locally
            notifications.value.forEach(n => {
                n.read_at = n.read_at || new Date().toISOString();
            });
            localUnreadCount.value = 0;
            
            // Reload notifications
            await fetchNotifications();
            // Reload page to update unread count
            router.reload({ only: ['notifications'] });
        } else {
            throw new Error('Failed to mark all as read');
        }
    } catch (error) {
        console.error('Failed to mark all as read:', error);
        // Optionally show a toast notification to the user
    }
};

const getNotificationUrl = (notification: Notification): string => {
    const data = notification.data;
    if (data.type === 'order' && data.order_id) {
        return `/orders/${data.order_id}`;
    } else if (data.type === 'form_submission' && data.submission_id) {
        return `/submissions/entries/${data.submission_id}`;
    }
    return '#';
};

// Real-time notification handler
const handleRealTimeNotification = (data: any) => {
    // Add new notification to the top of the list
    const newNotification: Notification = {
        id: data.id,
        type: data.type,
        message: data.message,
        read_at: null,
        created_at: data.created_at,
        data: data.data || {},
    };
    
    // Add to the beginning of the list
    notifications.value = [newNotification, ...notifications.value];
    
    // Update local unread count
    localUnreadCount.value += 1;
    
    // Reload page props to sync unread count
    router.reload({ only: ['notifications'] });
};

const { onNotification, offNotification } = useEchoNotifications();

onMounted(() => {
    const user = page.props.auth?.user;
    
    // Only setup if user is authenticated
    if (!user) {
        return;
    }
    
    // Fetch initial notifications
    fetchNotifications();
    
    // Initialize local unread count from props
    try {
        localUnreadCount.value = (page.props as any).notifications?.unread_count ?? 0;
    } catch {
        localUnreadCount.value = 0;
    }
    
    // Subscribe to shared Echo channel for real-time notifications
    try {
        const uid = Number((user as any).id);
        if (uid && Number.isFinite(uid)) {
            onNotification('bell', (data: any) => {
                handleRealTimeNotification(data);
            }, uid);
        }
    } catch (error) {
        console.error('Failed to setup real-time notifications:', error);
    }
});

onUnmounted(() => {
    offNotification('bell');
});
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger :as-child="true">
            <Button
                variant="ghost"
                size="icon"
                class="relative h-9 w-9"
            >
                <Bell class="h-5 w-5" />
                <Badge
                    v-if="unreadCount > 0"
                    class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full p-0 text-xs"
                    variant="destructive"
                >
                    {{ unreadCount > 99 ? '99+' : unreadCount }}
                </Badge>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <div class="flex items-center justify-between p-2">
                <h3 class="text-sm font-semibold">Notifications</h3>
                <Button
                    v-if="unreadCount > 0"
                    variant="ghost"
                    size="sm"
                    class="h-7 text-xs"
                    @click="markAllAsRead"
                >
                    Mark all as read
                </Button>
            </div>
            <DropdownMenuSeparator />
            <div class="max-h-[400px] overflow-y-auto">
                <div v-if="loading && notifications.length === 0" class="p-4 text-center text-sm text-muted-foreground">
                    Loading...
                </div>
                <div v-else-if="notifications.length === 0" class="p-4 text-center text-sm text-muted-foreground">
                    No notifications
                </div>
                <template v-else>
                    <DropdownMenuItem
                        v-for="notification in notifications"
                        :key="notification.id"
                        :class="[
                            'flex flex-col items-start gap-1 p-3 cursor-pointer',
                            !notification.read_at ? 'bg-accent' : ''
                        ]"
                        @click="handleNotificationClick(notification)"
                    >
                        <div class="flex w-full items-start justify-between gap-2">
                            <p class="text-sm font-medium leading-none">
                                {{ notification.message }}
                            </p>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ formatTimeAgo(notification.created_at) }}
                        </p>
                    </DropdownMenuItem>
                </template>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

