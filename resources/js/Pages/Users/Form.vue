<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import Card from '@/Components/Card.vue';
import Button from '@/Components/Button.vue';
import Input from '@/Components/Input.vue';
import Select from '@/Components/Select.vue';
import Textarea from '@/Components/Textarea.vue';
import Icons from '@/Components/Icons.vue';

const props = defineProps({
    user: Object,
    userPermissions: Array,
    managedCalendarIds: { type: Array, default: () => [] },
    availableCalendarUsers: { type: Array, default: () => [] },
    roles: Object,
    statuses: Object,
    permissions: Object,
    fakturowniaDepartments: Array,
    apiloPlatforms: {
        type: Array,
        default: () => [],
    },
});

const isEditing = !!props.user;

// Avatar
const avatarPreview = ref(props.user?.avatar_url || null);
const avatarInput = ref(null);

function onAvatarChange(e) {
    const file = e.target.files[0];
    if (!file) return;

    // Podgląd
    const reader = new FileReader();
    reader.onload = (ev) => { avatarPreview.value = ev.target.result; };
    reader.readAsDataURL(file);

    // Upload
    const formData = new FormData();
    formData.append('avatar', file);

    router.post(route('users.avatar', props.user.id), formData, {
        preserveScroll: true,
        onSuccess: () => {
            // avatarPreview zaktualizuje się po odświeżeniu props
        },
    });
}

function removeAvatar() {
    if (!confirm('Czy na pewno usunąć avatar?')) return;
    router.delete(route('users.avatar.delete', props.user.id), {
        preserveScroll: true,
        onSuccess: () => { avatarPreview.value = null; },
    });
}

const form = useForm({
    name: props.user?.name || '',
    email: props.user?.email || '',
    password: '',
    password_confirmation: '',
    phone: props.user?.phone || '',
    position: props.user?.position || '',
    role: props.user?.role || 'user',
    status: props.user?.status || 'active',
    notes: props.user?.notes || '',
    permissions: props.userPermissions || [],
    managed_calendars: props.managedCalendarIds || [],
    fakturownia_department_id: props.user?.fakturownia_department_id || null,
    fakturownia_department_name: props.user?.fakturownia_department_name || '',
    apilo_default_platform_id:
        props.user?.apilo_default_platform_id != null && props.user?.apilo_default_platform_id !== ''
            ? props.user.apilo_default_platform_id
            : '',
    play_phone: props.user?.play_phone || '',
});

// Aktualizuj nazwę działu gdy zmienia się ID
watch(() => form.fakturownia_department_id, (newId) => {
    if (newId) {
        const dept = props.fakturowniaDepartments?.find(d => d.id === parseInt(newId));
        form.fakturownia_department_name = dept?.shortcut || dept?.name || '';
    } else {
        form.fakturownia_department_name = '';
    }
});

// Sprawdź czy integracja z Fakturownia jest włączona
const hasFakturowniaIntegration = computed(() => {
    return props.fakturowniaDepartments && props.fakturowniaDepartments.length > 0;
});

const hasApiloPlatforms = computed(() => props.apiloPlatforms && props.apiloPlatforms.length > 0);

function togglePermission(permissionId) {
    const index = form.permissions.indexOf(permissionId);
    if (index > -1) {
        form.permissions.splice(index, 1);
    } else {
        form.permissions.push(permissionId);
    }
}

function toggleManagedCalendar(userId) {
    const index = form.managed_calendars.indexOf(userId);
    if (index > -1) {
        form.managed_calendars.splice(index, 1);
    } else {
        form.managed_calendars.push(userId);
    }
}

function submit() {
    if (isEditing) {
        form.put(route('users.update', props.user.id));
    } else {
        form.post(route('users.store'));
    }
}
</script>

<template>
    <Head :title="isEditing ? 'Edytuj użytkownika' : 'Nowy użytkownik'" />

    <div class="max-w-3xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ isEditing ? 'Edytuj użytkownika' : 'Nowy użytkownik' }}</h1>
                <p class="text-gray-600">{{ isEditing ? 'Zaktualizuj dane użytkownika' : 'Dodaj nowego użytkownika do systemu' }}</p>
            </div>
            <Link :href="route('users.index')">
                <Button variant="secondary">
                    <Icons name="arrow-left" class="w-5 h-5 mr-2" />
                    Powrót
                </Button>
            </Link>
        </div>

        <!-- Avatar upload (tylko przy edycji) -->
        <Card v-if="isEditing" title="Avatar">
            <div class="flex items-center gap-6">
                <!-- Podgląd -->
                <div class="shrink-0">
                    <div v-if="avatarPreview" class="relative group">
                        <img 
                            :src="avatarPreview" 
                            :alt="user.name" 
                            class="w-20 h-20 rounded-full object-cover border-2 border-slate-200 dark:border-slate-600"
                        />
                        <button 
                            type="button"
                            @click="removeAvatar"
                            class="absolute -top-1 -right-1 w-6 h-6 bg-red-500 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity text-xs font-bold hover:bg-red-600"
                            title="Usuń avatar"
                        >
                            ✕
                        </button>
                    </div>
                    <div v-else class="w-20 h-20 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center text-brand-primary text-2xl font-bold">
                        {{ user.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() }}
                    </div>
                </div>

                <!-- Upload -->
                <div>
                    <input 
                        ref="avatarInput"
                        type="file" 
                        accept="image/jpeg,image/png,image/webp" 
                        class="hidden"
                        @change="onAvatarChange"
                    />
                    <Button type="button" variant="secondary" @click="$refs.avatarInput.click()">
                        <Icons name="upload" class="w-4 h-4 mr-2" />
                        {{ avatarPreview ? 'Zmień avatar' : 'Wgraj avatar' }}
                    </Button>
                    <p class="mt-2 text-xs text-foreground-muted">JPG, PNG lub WebP. Max 2 MB.</p>
                </div>
            </div>
        </Card>

        <form @submit.prevent="submit" class="space-y-6">
            <Card title="Dane podstawowe">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Imię i nazwisko *</label>
                        <Input v-model="form.name" />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                        <Input v-model="form.email" type="email" />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Telefon</label>
                        <Input v-model="form.phone" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Stanowisko</label>
                        <Input v-model="form.position" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rola *</label>
                        <Select v-model="form.role" :options="roles" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status *</label>
                        <Select v-model="form.status" :options="statuses" />
                    </div>
                </div>
            </Card>

            <Card :title="isEditing ? 'Zmiana hasła' : 'Hasło'">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            {{ isEditing ? 'Nowe hasło' : 'Hasło' }} {{ !isEditing ? '*' : '' }}
                        </label>
                        <Input v-model="form.password" type="password" />
                        <p v-if="isEditing" class="mt-1 text-xs text-gray-500">Pozostaw puste, aby nie zmieniać hasła</p>
                        <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Powtórz hasło {{ !isEditing ? '*' : '' }}</label>
                        <Input v-model="form.password_confirmation" type="password" />
                    </div>
                </div>
            </Card>

            <!-- Uprawnienia (tylko dla nie-adminów) -->
            <Card v-if="form.role !== 'admin'" title="Uprawnienia">
                <p class="text-sm text-gray-500 mb-4">Wybierz uprawnienia dla użytkownika. Administratorzy mają pełne uprawnienia.</p>
                
                <div class="space-y-6">
                    <div v-for="(perms, module) in permissions" :key="module" class="border-b border-gray-200 pb-4 last:border-0 last:pb-0">
                        <h4 class="text-sm font-medium text-gray-900 mb-3 capitalize">{{ module }}</h4>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                            <label v-for="perm in perms" :key="perm.id" class="flex items-center">
                                <input 
                                    type="checkbox" 
                                    :checked="form.permissions.includes(perm.id)"
                                    @change="togglePermission(perm.id)"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-brand-primary" 
                                />
                                <span class="ml-2 text-sm text-gray-700">{{ perm.name }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </Card>

            <Card v-else>
                <div class="flex items-center p-4 bg-purple-50 rounded-lg">
                    <Icons name="info" class="w-5 h-5 text-purple-600 mr-3" />
                    <p class="text-sm text-purple-700">Administratorzy mają pełne uprawnienia do wszystkich funkcji systemu.</p>
                </div>
            </Card>

            <!-- Zarządzane kalendarze (m2m) -->
            <Card v-if="isEditing" title="Zarządzane kalendarze">
                <p class="text-sm text-gray-500 mb-4">
                    Wybrane kalendarze pojawią się jako pozycje w dropdownie tego użytkownika na stronie Kalendarz.
                    Może wtedy oglądać i edytować wizyty z tych kalendarzy tak jakby były jego.
                </p>
                <div v-if="availableCalendarUsers.length === 0" class="text-sm text-gray-400 italic">
                    Brak innych użytkowników do wyboru.
                </div>
                <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <label v-for="cu in availableCalendarUsers" :key="cu.id"
                           class="flex items-center p-2 rounded hover:bg-gray-50 cursor-pointer">
                        <input
                            type="checkbox"
                            :checked="form.managed_calendars.includes(cu.id)"
                            @change="toggleManagedCalendar(cu.id)"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-brand-primary"
                        />
                        <span class="ml-2 text-sm text-gray-700">
                            {{ cu.name }}
                            <span class="text-gray-400 text-xs">({{ cu.email }})</span>
                        </span>
                    </label>
                </div>
            </Card>

            <!-- Integracja z Fakturownia -->
            <Card v-if="hasFakturowniaIntegration" title="Integracja z Fakturownia">
                <div class="space-y-4">
                    <p class="text-sm text-gray-500">
                        Przypisz użytkownika do działu w Fakturownia. Statystyki przychodów będą filtrowane dla tego działu.
                    </p>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Dział w Fakturownia</label>
                        <select 
                            v-model="form.fakturownia_department_id"
                            class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-primary focus:border-brand-primary"
                        >
                            <option :value="null">-- Brak przypisania --</option>
                            <option 
                                v-for="dept in fakturowniaDepartments" 
                                :key="dept.id" 
                                :value="dept.id"
                            >
                                {{ dept.shortcut || dept.name }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Przypisanie do działu pozwala na filtrowanie statystyk i faktur.
                        </p>
                    </div>

                    <div v-if="form.fakturownia_department_name" class="flex items-center gap-2 p-3 bg-green-50 rounded-lg">
                        <Icons name="check" class="w-5 h-5 text-green-600" />
                        <span class="text-sm text-green-700">
                            Przypisany do działu: <strong>{{ form.fakturownia_department_name }}</strong>
                        </span>
                    </div>
                </div>
            </Card>

            <Card title="Apilo — zamówienia z kalendarza">
                <div class="space-y-4">
                    <p class="text-sm text-gray-500">
                        Domyślny kanał sprzedaży przy tworzeniu zamówienia w panelu wizyty (zakładka Apilo).
                        Typ płatności domyślnie ustawiany jest na <strong>pobranie</strong>, jeśli Apilo zwraca taki wariant w mapowaniu płatności.
                    </p>
                    <div v-if="hasApiloPlatforms">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Domyślny kanał sprzedaży</label>
                        <select
                            v-model="form.apilo_default_platform_id"
                            class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-primary focus:border-brand-primary"
                        >
                            <option value="">— Systemowy (pierwszy z listy Apilo) —</option>
                            <option
                                v-for="p in apiloPlatforms"
                                :key="String(p.id)"
                                :value="p.id"
                            >
                                {{ p.name || ('Platforma #' + p.id) }}
                            </option>
                        </select>
                        <p v-if="form.errors.apilo_default_platform_id" class="mt-1 text-sm text-red-600">
                            {{ form.errors.apilo_default_platform_id }}
                        </p>
                    </div>
                    <div v-else class="flex items-center gap-2 p-3 bg-amber-50 rounded-lg text-sm text-amber-900">
                        <Icons name="info" class="w-5 h-5 shrink-0 text-amber-600" />
                        <span>Brak kanałów z Apilo (sprawdź konfigurację integracji lub odśwież stronę później).</span>
                    </div>
                </div>
            </Card>

            <Card title="Play Wirtualna Centralka">
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">
                            Numer telefonu w centrali Play
                        </label>
                        <Input
                            v-model="form.play_phone"
                            placeholder="np. 48123456789"
                            class="w-full"
                        />
                        <p class="text-xs text-foreground-muted mt-1">
                            Format: 48XXXXXXXXX (z prefiksem kraju). Używany do dopasowania połączeń i click-to-call.
                        </p>
                        <p v-if="form.errors.play_phone" class="text-xs text-red-500 mt-1">{{ form.errors.play_phone }}</p>
                    </div>
                </div>
            </Card>

            <Card title="Notatki">
                <Textarea v-model="form.notes" :rows="3" placeholder="Dodatkowe informacje o użytkowniku..." />
            </Card>

            <div class="flex items-center justify-end gap-3">
                <Link :href="route('users.index')">
                    <Button variant="secondary" type="button">Anuluj</Button>
                </Link>
                <Button :loading="form.processing">
                    {{ isEditing ? 'Zapisz zmiany' : 'Dodaj użytkownika' }}
                </Button>
            </div>
        </form>
    </div>
</template>
