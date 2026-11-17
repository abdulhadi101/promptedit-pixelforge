<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { formatNano } from '@/lib/money'

const props = defineProps({
  perDay: Array,
  perUser: Array,
  daysWindow: Number,
})

function mismatch(row) {
  return row.pixelforge_nano !== null && row.pixelforge_nano !== row.provider_cost_nano
}
</script>

<template>
  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Admin report (last {{ daysWindow }} days, UTC completion days)</h2>
    </template>

    <div class="py-8 max-w-6xl mx-auto px-4 space-y-10">
      <section>
        <h3 class="mb-2 text-sm font-medium text-gray-700">Per day</h3>
        <table class="w-full text-sm border rounded-lg overflow-hidden">
          <thead class="bg-gray-50 text-left text-gray-600">
            <tr>
              <th class="p-2">Day (UTC)</th>
              <th class="p-2 text-right">Credits spent</th>
              <th class="p-2 text-right">Provider cost (ours)</th>
              <th class="p-2 text-right">Provider cost (Pixelforge)</th>
              <th class="p-2 text-right">Profit</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="perDay.length === 0">
              <td colspan="5" class="p-3 text-gray-500">No completed generations yet.</td>
            </tr>
            <tr v-for="row in perDay" :key="row.day" class="border-t" :class="mismatch(row) ? 'bg-red-50' : ''">
              <td class="p-2">{{ row.day }}</td>
              <td class="p-2 text-right">{{ formatNano(row.charge_nano) }}</td>
              <td class="p-2 text-right">{{ formatNano(row.provider_cost_nano) }}</td>
              <td class="p-2 text-right">
                <template v-if="row.pixelforge_nano === null">n/a</template>
                <template v-else-if="mismatch(row)" class="text-red-600">
                  MISMATCH: {{ formatNano(row.pixelforge_nano) }}
                </template>
                <template v-else>{{ formatNano(row.pixelforge_nano) }}</template>
              </td>
              <td class="p-2 text-right">{{ formatNano(row.profit_nano) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section>
        <h3 class="mb-2 text-sm font-medium text-gray-700">Per user</h3>
        <table class="w-full text-sm border rounded-lg overflow-hidden">
          <thead class="bg-gray-50 text-left text-gray-600">
            <tr>
              <th class="p-2">User</th>
              <th class="p-2 text-right">Credits spent</th>
              <th class="p-2 text-right">Provider cost</th>
              <th class="p-2 text-right">Profit</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="perUser.length === 0">
              <td colspan="4" class="p-3 text-gray-500">No completed generations yet.</td>
            </tr>
            <tr v-for="row in perUser" :key="row.email" class="border-t">
              <td class="p-2">{{ row.name }} <span class="text-gray-500">({{ row.email }})</span></td>
              <td class="p-2 text-right">{{ formatNano(row.charge_nano) }}</td>
              <td class="p-2 text-right">{{ formatNano(row.provider_cost_nano) }}</td>
              <td class="p-2 text-right">{{ formatNano(row.profit_nano) }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </div>
  </AuthenticatedLayout>
</template>
