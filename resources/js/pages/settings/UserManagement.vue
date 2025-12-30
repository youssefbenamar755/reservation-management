<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { index } from '@/routes/users';
import type { PaginatedData, User } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Trash2, Pencil, UserPlus } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';

interface Props {
    users: PaginatedData<User>;
    filters?: {
        search?: string;
    };
}

const props = defineProps<Props>();

const breadcrumbItems = [
    {
        title: 'User Management',
        href: index().url,
    },
];

// Dialog states
const showAddDialog = ref(false);
const showEditDialog = ref(false);
const showDeleteDialog = ref(false);
const selectedUser = ref<User | null>(null);

// Form data
const addForm = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_admin: false,
    processing: false,
    errors: {} as Record<string, string>,
});

const editForm = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_admin: false,
    processing: false,
    errors: {} as Record<string, string>,
});

const search = ref(props.filters?.search || '');

// Functions
function openAddDialog() {
    addForm.value = {
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        is_admin: false,
        processing: false,
        errors: {},
    };
    showAddDialog.value = true;
}

function openEditDialog(user: User) {
    selectedUser.value = user;
    editForm.value = {
        name: user.name,
        email: user.email,
        password: '',
        password_confirmation: '',
        is_admin: user.is_admin || false,
        processing: false,
        errors: {},
    };
    showEditDialog.value = true;
}

function openDeleteDialog(user: User) {
    selectedUser.value = user;
    showDeleteDialog.value = true;
}

function handleAddUser() {
    addForm.value.processing = true;
    addForm.value.errors = {};

    router.post('/settings/users', {
        name: addForm.value.name,
        email: addForm.value.email,
        password: addForm.value.password,
        password_confirmation: addForm.value.password_confirmation,
        is_admin: addForm.value.is_admin,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showAddDialog.value = false;
            addForm.value.processing = false;
        },
        onError: (errors) => {
            addForm.value.errors = errors;
            addForm.value.processing = false;
        },
    });
}

function handleEditUser() {
    if (!selectedUser.value) return;

    editForm.value.processing = true;
    editForm.value.errors = {};

    const data: any = {
        name: editForm.value.name,
        email: editForm.value.email,
        is_admin: editForm.value.is_admin,
    };

    // Only include password if it's filled
    if (editForm.value.password) {
        data.password = editForm.value.password;
        data.password_confirmation = editForm.value.password_confirmation;
    }

    router.put(`/settings/users/${selectedUser.value.id}`, data, {
        preserveScroll: true,
        onSuccess: () => {
            showEditDialog.value = false;
            editForm.value.processing = false;
        },
        onError: (errors) => {
            editForm.value.errors = errors;
            editForm.value.processing = false;
        },
    });
}

function handleDeleteUser() {
    if (!selectedUser.value) return;

    router.delete(`/settings/users/${selectedUser.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteDialog.value = false;
        },
    });
}

function performSearch() {
    router.get(index().url, { search: search.value }, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="User Management" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <div class="flex items-center justify-between">
                    <HeadingSmall
                        title="User Management"
                        description="Manage users and their permissions"
                    />
                    <Button @click="openAddDialog" size="sm">
                        <UserPlus class="mr-2 h-4 w-4" />
                        Add User
                    </Button>
                </div>

                <!-- Search -->
                <div class="flex gap-2">
                    <Input
                        v-model="search"
                        placeholder="Search users..."
                        @keydown.enter="performSearch"
                        class="max-w-sm"
                    />
                    <Button @click="performSearch" variant="secondary">Search</Button>
                </div>

                <!-- Users Table -->
                <div class="rounded-md border overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/50">
                            <tr>
                                <th class="h-12 px-4 text-left align-middle font-medium">Name</th>
                                <th class="h-12 px-4 text-left align-middle font-medium">Email</th>
                                <th class="h-12 px-4 text-left align-middle font-medium">Role</th>
                                <th class="h-12 px-4 text-right align-middle font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="user in users.data"
                                :key="user.id"
                                class="border-b transition-colors hover:bg-muted/50"
                            >
                                <td class="p-4 align-middle font-medium">{{ user.name }}</td>
                                <td class="p-4 align-middle">{{ user.email }}</td>
                                <td class="p-4 align-middle">
                                    <Badge v-if="user.is_admin" variant="default">Admin</Badge>
                                    <Badge v-else variant="secondary">User</Badge>
                                </td>
                                <td class="p-4 align-middle text-right">
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            @click="openEditDialog(user)"
                                            variant="ghost"
                                            size="icon"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </Button>
                                        <Button
                                            @click="openDeleteDialog(user)"
                                            variant="ghost"
                                            size="icon"
                                        >
                                            <Trash2 class="h-4 w-4 text-destructive" />
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="users.last_page > 1" class="flex items-center justify-center gap-2">
                    <Link
                        v-if="users.prev_page_url"
                        :href="users.prev_page_url"
                        class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2"
                    >
                        Previous
                    </Link>
                    <span class="text-sm">
                        Page {{ users.current_page }} of {{ users.last_page }}
                    </span>
                    <Link
                        v-if="users.next_page_url"
                        :href="users.next_page_url"
                        class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2"
                    >
                        Next
                    </Link>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>

    <!-- Add User Dialog -->
    <Dialog v-model:open="showAddDialog">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add New User</DialogTitle>
                <DialogDescription>Create a new user account</DialogDescription>
            </DialogHeader>

            <div class="grid gap-4 py-4">
                <div class="grid gap-2">
                    <Label for="add-name">Name</Label>
                    <Input id="add-name" v-model="addForm.name" />
                    <InputError :message="addForm.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="add-email">Email</Label>
                    <Input id="add-email" type="email" v-model="addForm.email" />
                    <InputError :message="addForm.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="add-password">Password</Label>
                    <Input id="add-password" type="password" v-model="addForm.password" />
                    <InputError :message="addForm.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="add-password-confirmation">Confirm Password</Label>
                    <Input
                        id="add-password-confirmation"
                        type="password"
                        v-model="addForm.password_confirmation"
                    />
                </div>

                <div class="flex items-center space-x-2">
                    <input
                        id="add-is-admin"
                        type="checkbox"
                        v-model="addForm.is_admin"
                        class="h-4 w-4 rounded border-gray-300"
                    />
                    <Label for="add-is-admin" class="cursor-pointer">Admin User</Label>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="showAddDialog = false">Cancel</Button>
                <Button @click="handleAddUser" :disabled="addForm.processing">
                    Create User
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Edit User Dialog -->
    <Dialog v-model:open="showEditDialog">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Edit User</DialogTitle>
                <DialogDescription>Update user information</DialogDescription>
            </DialogHeader>

            <div class="grid gap-4 py-4">
                <div class="grid gap-2">
                    <Label for="edit-name">Name</Label>
                    <Input id="edit-name" v-model="editForm.name" />
                    <InputError :message="editForm.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-email">Email</Label>
                    <Input id="edit-email" type="email" v-model="editForm.email" />
                    <InputError :message="editForm.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-password">New Password (optional)</Label>
                    <Input id="edit-password" type="password" v-model="editForm.password" />
                    <InputError :message="editForm.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="edit-password-confirmation">Confirm Password</Label>
                    <Input
                        id="edit-password-confirmation"
                        type="password"
                        v-model="editForm.password_confirmation"
                    />
                </div>

                <div class="flex items-center space-x-2">
                    <input
                        id="edit-is-admin"
                        type="checkbox"
                        v-model="editForm.is_admin"
                        class="h-4 w-4 rounded border-gray-300"
                    />
                    <Label for="edit-is-admin" class="cursor-pointer">Admin User</Label>
                    <InputError :message="editForm.errors.is_admin" />
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="showEditDialog = false">Cancel</Button>
                <Button @click="handleEditUser" :disabled="editForm.processing">
                    Save Changes
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Delete User Dialog -->
    <Dialog v-model:open="showDeleteDialog">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete User</DialogTitle>
                <DialogDescription>
                    Are you sure you want to delete {{ selectedUser?.name }}? This action cannot be undone.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button variant="outline" @click="showDeleteDialog = false">Cancel</Button>
                <Button variant="destructive" @click="handleDeleteUser">
                    Delete User
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
