<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Icons from '@/Components/Icons.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Textarea from '@/Components/Textarea.vue';

const props = defineProps({ modelValue: Boolean });
const emit = defineEmits(['update:modelValue']);

const form = useForm({
    subject:    '',
    category:   'bug',
    message:    '',
    attach_log: true,
});

const categories = [
    { value: 'bug',      label: 'Błąd / awaria',      icon: 'alert' },
    { value: 'question', label: 'Pytanie',            icon: 'info' },
    { value: 'feature',  label: 'Sugestia / pomysł',  icon: 'sparkles' },
    { value: 'other',    label: 'Inne',               icon: 'mail' },
];

function submit() {
    form.post(route('support.submit'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.attach_log = true;
            emit('update:modelValue', false);
        },
    });
}

function close() {
    if (form.processing) return;
    emit('update:modelValue', false);
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="modelValue"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 style="background: rgba(0,0,0,0.65); backdrop-filter: blur(4px);"
                 @click.self="close">
                <div class="glass-card rounded-xl w-full max-w-lg max-h-[90vh] overflow-hidden flex flex-col"
                     style="background: var(--color-surface);">
                    <header class="px-6 py-4 border-b border-border flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg gradient-brand flex items-center justify-center">
                                <Icons name="mail" class="w-4 h-4 text-white" />
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-foreground">Zgłoś problem</h2>
                                <p class="text-xs text-foreground-muted">Trafi do supportu OVERMEDIA</p>
                            </div>
                        </div>
                        <button type="button" @click="close" class="p-1 rounded hover:bg-surface-elevated text-foreground-muted hover:text-foreground transition-colors" :disabled="form.processing">
                            <Icons name="close" class="w-5 h-5" />
                        </button>
                    </header>

                    <form @submit.prevent="submit" class="p-6 space-y-4 overflow-y-auto">
                        <!-- Kategoria -->
                        <div>
                            <label class="text-sm font-medium text-foreground mb-2 block">Kategoria</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button v-for="cat in categories" :key="cat.value"
                                        type="button"
                                        @click="form.category = cat.value"
                                        :class="['flex items-center gap-2 px-3 py-2 rounded-md border text-sm transition-colors',
                                                  form.category === cat.value
                                                      ? 'border-brand-primary bg-brand-primary/10 text-brand-primary'
                                                      : 'border-border bg-surface-elevated text-foreground hover:border-border-bright']">
                                    <Icons :name="cat.icon" class="w-4 h-4" />
                                    {{ cat.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Temat -->
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-foreground">Temat</label>
                            <Input v-model="form.subject" placeholder="np. Nie mogę zapisać klienta" required maxlength="200" />
                            <p v-if="form.errors.subject" class="text-xs text-destructive">{{ form.errors.subject }}</p>
                        </div>

                        <!-- Wiadomość -->
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-foreground">Opis problemu</label>
                            <Textarea v-model="form.message" :rows="6" placeholder="Co się stało? Co próbowałeś zrobić? Jakie były kroki?" required maxlength="5000" />
                            <p v-if="form.errors.message" class="text-xs text-destructive">{{ form.errors.message }}</p>
                            <p class="text-xs text-foreground-muted">{{ form.message.length }}/5000</p>
                        </div>

                        <!-- Załącz logi -->
                        <label class="flex items-start gap-3 surface-elevated rounded-md p-3 cursor-pointer">
                            <input type="checkbox" v-model="form.attach_log" class="mt-0.5 rounded border-border-bright text-brand-primary focus:ring-brand-primary" />
                            <span class="flex-1">
                                <span class="text-sm font-medium text-foreground block">Załącz log aplikacji</span>
                                <span class="text-xs text-foreground-muted">
                                    Ostatnie ~200 linii z <code class="font-mono">storage/logs/laravel.log</code> — pomaga supportowi szybciej zdiagnozować problem.
                                </span>
                            </span>
                        </label>
                    </form>

                    <footer class="px-6 py-4 border-t border-border flex justify-end gap-3 bg-surface-2">
                        <Button type="button" variant="secondary" @click="close" :disabled="form.processing">Anuluj</Button>
                        <Button type="button" @click="submit" :loading="form.processing" :disabled="!form.subject.trim() || !form.message.trim()">
                            <Icons name="check" class="w-4 h-4" />
                            Wyślij zgłoszenie
                        </Button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
