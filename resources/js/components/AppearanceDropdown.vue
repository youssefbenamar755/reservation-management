<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const { appearance, updateAppearance } = useAppearance();

const themes = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="h-9 w-9 rounded-full">
                <Sun v-if="appearance === 'light'" class="h-4 w-4" />
                <Moon v-else-if="appearance === 'dark'" class="h-4 w-4" />
                <Monitor v-else class="h-4 w-4" />
                <span class="sr-only">Toggle theme</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="{ value, Icon, label } in themes"
                :key="value"
                @click="updateAppearance(value)"
                class="cursor-pointer"
            >
                <component :is="Icon" class="mr-2 h-4 w-4" />
                <span :class="{ 'font-bold': appearance === value }">{{ label }}</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
