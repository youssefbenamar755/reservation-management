<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

interface SubmissionMeta {
  status?: string
}

interface Props {
  fieldCount: number
  submissionMeta: SubmissionMeta
}

const props = defineProps<Props>()
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>Statistics</CardTitle>
      <CardDescription>Entry summary</CardDescription>
    </CardHeader>
    <CardContent class="space-y-3">
      <div class="flex justify-between items-center p-3 rounded-lg bg-muted/30">
        <span class="text-sm font-medium text-muted-foreground">Form Fields</span>
        <Badge variant="outline" class="font-semibold">
          {{ fieldCount }}
        </Badge>
      </div>
      <div v-if="submissionMeta.status" class="flex justify-between items-center p-3 rounded-lg bg-muted/30">
        <span class="text-sm font-medium text-muted-foreground">Status</span>
        <Badge variant="outline" class="font-semibold">
          {{ submissionMeta.status }}
        </Badge>
      </div>
    </CardContent>
  </Card>
</template>
