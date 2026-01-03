<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { AlertCircle, CheckCircle2, RefreshCw } from 'lucide-vue-next';

interface UpdateStatus {
    app_name: string;
    code_version: string;
    code_build: string;
    installed_version: string | null;
    installed_build: string | null;
    update_required: boolean;
    status: string;
}

interface Props {
    updateStatus: UpdateStatus;
}

defineProps<Props>();

const showConfirmDialog = ref(false);
const isProcessing = ref(false);

const breadcrumbItems = [
    {
        title: 'System Updates',
        href: '/settings/updates',
    },
];

function handleRunUpdate() {
    showConfirmDialog.value = true;
}

function confirmRunUpdate() {
    isProcessing.value = true;
    showConfirmDialog.value = false;

    router.post('/settings/updates/run', {}, {
        preserveScroll: true,
        onFinish: () => {
            isProcessing.value = false;
        },
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="System Updates" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="System Updates"
                    description="Manage application version and updates"
                />

                <!-- Update Required Alert -->
                <Alert
                    v-if="updateStatus.update_required"
                    variant="destructive"
                >
                    <AlertCircle class="h-4 w-4" />
                    <AlertTitle>Update Required</AlertTitle>
                    <AlertDescription>
                        A new version of the application is available. Please run the update process to apply changes.
                    </AlertDescription>
                </Alert>

                <!-- Version Information Card -->
                <Card>
                    <CardHeader>
                        <CardTitle>Version Information</CardTitle>
                        <CardDescription>
                            Current application version details
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">App Name</p>
                                <p class="mt-1 text-lg font-semibold">{{ updateStatus.app_name }}</p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Status</p>
                                <div class="mt-1">
                                    <Badge
                                        :variant="updateStatus.update_required ? 'destructive' : 'default'"
                                    >
                                        {{ updateStatus.status }}
                                    </Badge>
                                </div>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Code Version</p>
                                <p class="mt-1 text-lg font-mono">{{ updateStatus.code_version }}</p>
                                <p class="text-xs text-muted-foreground">Build: {{ updateStatus.code_build }}</p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Installed Version</p>
                                <p v-if="updateStatus.installed_version" class="mt-1 text-lg font-mono">
                                    {{ updateStatus.installed_version }}
                                </p>
                                <p v-else class="mt-1 text-sm text-muted-foreground italic">
                                    Not set (initial installation)
                                </p>
                                <p
                                    v-if="updateStatus.installed_build"
                                    class="text-xs text-muted-foreground"
                                >
                                    Build: {{ updateStatus.installed_build }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Update Actions Card -->
                <Card>
                    <CardHeader>
                        <CardTitle>Update Actions</CardTitle>
                        <CardDescription>
                            Finalize updates after uploading new code files
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="rounded-lg border bg-muted/50 p-4">
                            <h4 class="mb-2 text-sm font-medium">Update Process</h4>
                            <ul class="space-y-2 text-sm text-muted-foreground">
                                <li class="flex items-start gap-2">
                                    <CheckCircle2 class="mt-0.5 h-4 w-4 flex-shrink-0" />
                                    <span>Run database migrations</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <CheckCircle2 class="mt-0.5 h-4 w-4 flex-shrink-0" />
                                    <span>Clear application caches</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <CheckCircle2 class="mt-0.5 h-4 w-4 flex-shrink-0" />
                                    <span>Update installed version record</span>
                                </li>
                            </ul>
                        </div>

                        <div class="flex items-center gap-4">
                            <Button
                                @click="handleRunUpdate"
                                :disabled="isProcessing"
                                variant="default"
                            >
                                <RefreshCw
                                    :class="[
                                        'mr-2 h-4 w-4',
                                        { 'animate-spin': isProcessing }
                                    ]"
                                />
                                {{ isProcessing ? 'Running Update...' : 'Run Update' }}
                            </Button>
                            <p class="text-sm text-muted-foreground">
                                This action will run migrations and clear caches. Make sure you have uploaded the new code files first.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    </AppLayout>

    <!-- Confirm Update Dialog -->
    <Dialog v-model:open="showConfirmDialog">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Confirm Update</DialogTitle>
                <DialogDescription>
                    Are you sure you want to run the update process? This will:
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2 py-4">
                <ul class="space-y-2 text-sm">
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5">•</span>
                        <span>Run all pending database migrations</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5">•</span>
                        <span>Clear application caches (config, route, view)</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="mt-0.5">•</span>
                        <span>Update the installed version to {{ updateStatus.code_version }}</span>
                    </li>
                </ul>

                <Alert variant="destructive" class="mt-4">
                    <AlertCircle class="h-4 w-4" />
                    <AlertTitle>Important</AlertTitle>
                    <AlertDescription>
                        Make sure you have already uploaded the new code files before running this update.
                    </AlertDescription>
                </Alert>
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    @click="showConfirmDialog = false"
                    :disabled="isProcessing"
                >
                    Cancel
                </Button>
                <Button
                    @click="confirmRunUpdate"
                    :disabled="isProcessing"
                    variant="default"
                >
                    <RefreshCw
                        v-if="isProcessing"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    Run Update
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

