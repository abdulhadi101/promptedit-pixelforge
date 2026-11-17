<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { formatNano } from '@/lib/money'

const props = defineProps({ generations: Array })

const form = useForm({
  request_id: crypto.randomUUID(),
  prompt: '',
  size: '1024x1024',
  quality: 'medium',
  reference_images: [],
})
const refsText = ref('')
const estimate = ref(null)
const estimateError = ref(null)

watch(refsText, (v) => {
  form.reference_images = v.split('\n').map((s) => s.trim()).filter(Boolean)
})

let timer
watch(
  () => [form.prompt, form.size, form.quality, form.reference_images.length],
  () => {
    clearTimeout(timer)
    if (!form.prompt) return (estimate.value = null)
    timer = setTimeout(async () => {
      try {
        const res = await fetch('/generations/estimate', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest', // required for Laravel to treat it as an AJAX request
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
          },
          body: JSON.stringify(form.data()),
        })
        if (!res.ok) throw new Error(`Estimate failed (${res.status})`)
        estimate.value = await res.json()
        estimateError.value = null
      } catch {
        estimate.value = null
        estimateError.value = 'Could not calculate an estimate.'
      }
    }, 300)
  },
)

function submit() {
  form.post('/generations', {
    preserveScroll: true,
    onSuccess: () => {
      form.reset('prompt')
      refsText.value = ''
      form.request_id = crypto.randomUUID() // new submission, new idempotency token
    },
  })
}

// Live status: poll only while something is in flight.
const inFlight = computed(() => props.generations.some((g) => ['queued', 'processing'].includes(g.status)))
let poll
onMounted(() => {
  poll = setInterval(() => inFlight.value && router.reload({ only: ['generations'] }), 3000)
})
onBeforeUnmount(() => clearInterval(poll))
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-8 p-6">
    <form class="space-y-4 rounded-lg border p-4" @submit.prevent="submit">
      <textarea v-model="form.prompt" rows="3" maxlength="4000" placeholder="Describe the image..." class="w-full rounded border p-2" />
      <p v-if="form.errors.prompt" class="text-sm text-red-600">{{ form.errors.prompt }}</p>

      <div class="flex gap-4">
        <select v-model="form.size" class="rounded border p-2">
          <option>1024x1024</option><option>1536x1024</option><option>1024x1536</option>
        </select>
        <select v-model="form.quality" class="rounded border p-2">
          <option>low</option><option>medium</option><option>high</option>
        </select>
      </div>

      <textarea v-model="refsText" rows="2" placeholder="Reference image URLs, one per line (max 4)" class="w-full rounded border p-2" />
      <p v-if="form.errors.reference_images || form.errors['reference_images.0']" class="text-sm text-red-600">
        Check your reference image URLs (http/https, max 4).
      </p>

      <div class="flex items-center justify-between">
        <p class="text-sm text-gray-600">
          <template v-if="estimate">
            Estimated <strong>{{ formatNano(estimate.expected) }}</strong>
            (between {{ formatNano(estimate.min) }} and {{ formatNano(estimate.max) }}; final price is set when the image finishes)
          </template>
          <template v-else-if="estimateError">{{ estimateError }}</template>
          <template v-else>Enter a prompt to see the price.</template>
        </p>
        <button :disabled="form.processing || !form.prompt" class="rounded bg-black px-4 py-2 text-white disabled:opacity-50">Generate</button>
      </div>
    </form>

    <ul class="space-y-3">
      <li v-for="g in generations" :key="g.id" class="flex gap-4 rounded-lg border p-3">
        <img v-if="g.image_url" :src="g.image_url" class="h-24 w-24 rounded object-cover" alt="" />
        <div v-else class="flex h-24 w-24 items-center justify-center rounded bg-gray-100 text-xs text-gray-500">
          {{ g.status === 'succeeded' ? 'Image expired' : g.status }}
        </div>
        <div class="flex-1 text-sm">
          <p class="font-medium">{{ g.prompt }}</p>
          <p class="text-gray-500">{{ g.size }} · {{ g.quality }} · <span class="uppercase">{{ g.status }}</span></p>
          <p v-if="g.status === 'failed'" class="text-red-600">{{ g.error }} You were not charged.</p>
          <p v-else-if="g.status === 'succeeded'">Charged <strong>{{ formatNano(g.charge_nano) }}</strong> (estimate was {{ formatNano(g.estimated_charge_nano) }})</p>
          <p v-else>Estimated {{ formatNano(g.estimated_charge_nano) }}</p>
        </div>
      </li>
    </ul>
  </div>
</template>
