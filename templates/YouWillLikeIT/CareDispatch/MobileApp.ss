<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f766e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>$PageTitle</title>
</head>
<body class="cd-mobile">
<noscript>Diese Ansicht benötigt JavaScript.</noscript>
<div id="care-mobile" x-data="careMobile" data-config="$BootConfig.ATT" x-cloak class="mx-auto flex min-h-dvh max-w-lg flex-col bg-slate-50 text-slate-900">

    <%-- Header --%>
    <header class="sticky top-0 z-20 bg-teal-700 px-4 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))] text-white shadow">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate text-sm opacity-80" x-text="state ? state.caregiver.qualification : ''"></p>
                <h1 class="truncate text-lg font-semibold" x-text="state ? state.caregiver.name : 'Meine Tour'"></h1>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="rounded-full px-2.5 py-1 text-xs font-semibold"
                      :class="attendanceBadge().cls" x-text="attendanceBadge().label"></span>
                <button type="button" class="rounded-lg p-2 hover:bg-teal-600" @click="refresh()" :disabled="busy" aria-label="Aktualisieren">
                    <svg class="h-5 w-5" :class="busy && 'animate-spin'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                </button>
            </div>
        </div>
    </header>

    <%-- Error / info --%>
    <template x-if="message">
        <div class="mx-4 mt-3 rounded-lg px-4 py-3 text-sm" role="alert"
             :class="message.type === 'error' ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800'">
            <div class="flex items-start justify-between gap-3">
                <p x-text="message.text"></p>
                <button type="button" class="font-bold" @click="message = null" aria-label="Schließen">×</button>
            </div>
        </div>
    </template>

    <main class="flex-1 space-y-4 px-4 pb-32 pt-4">
        <template x-if="!state && busy">
            <p class="py-10 text-center text-slate-500">Lade Tour …</p>
        </template>

        <%-- Attendance --%>
        <template x-if="state">
            <section class="rounded-xl bg-white p-4 shadow-sm">
                <template x-if="state.attendance.status === 'Absent'">
                    <button type="button" class="w-full rounded-lg bg-teal-600 py-3 text-base font-semibold text-white active:bg-teal-700"
                            @click="post('check-in')" :disabled="busy">Kommen buchen</button>
                </template>
                <template x-if="state.attendance.status !== 'Absent'">
                    <div class="space-y-3">
                        <p class="text-sm text-slate-600">
                            Eingestempelt seit <strong x-text="formatTime(state.attendance.checkIn)"></strong>
                            <span x-show="state.attendance.breakMinutes > 0">· Pause <span x-text="state.attendance.breakMinutes"></span> Min.</span>
                        </p>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" class="rounded-lg border border-slate-300 py-2.5 font-medium active:bg-slate-100"
                                    x-show="state.attendance.status === 'Active'" @click="post('break', {action: 'start'})" :disabled="busy">Pause</button>
                            <button type="button" class="rounded-lg bg-amber-500 py-2.5 font-medium text-white"
                                    x-show="state.attendance.status === 'Break'" @click="post('break', {action: 'end'})" :disabled="busy">Pause beenden</button>
                            <button type="button" class="rounded-lg border border-slate-300 py-2.5 font-medium active:bg-slate-100"
                                    @click="confirmCheckOut()" :disabled="busy">Gehen buchen</button>
                        </div>
                    </div>
                </template>
            </section>
        </template>

        <%-- Tours and visits --%>
        <template x-if="state && state.tours.length === 0">
            <p class="rounded-xl bg-white p-6 text-center text-slate-500 shadow-sm">Für heute ist dir keine Tour zugewiesen.</p>
        </template>

        <template x-for="tour in (state ? state.tours : [])" :key="tour.id">
            <section class="space-y-2">
                <div class="flex items-center justify-between px-1">
                    <h2 class="font-semibold" x-text="tour.title"></h2>
                    <span class="text-xs font-medium uppercase tracking-wide" :class="tourStatusClass(tour.status)" x-text="tourStatusLabel(tour.status)"></span>
                </div>

                <template x-for="visit in tour.visits" :key="visit.id">
                    <article class="overflow-hidden rounded-xl bg-white shadow-sm ring-1"
                             :class="visit.status === 'InProgress' ? 'ring-2 ring-teal-500' : 'ring-slate-200'">
                        <button type="button" class="flex w-full items-start gap-3 p-4 text-left" @click="toggle(visit.id)">
                            <div class="w-14 shrink-0 text-center">
                                <p class="text-lg font-semibold tabular-nums" x-text="visit.start"></p>
                                <p class="text-xs text-slate-500 tabular-nums" x-text="visit.end"></p>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold" x-text="visit.patient ? visit.patient.name : '–'"></p>
                                <p class="truncate text-sm text-slate-600" x-text="visit.patient ? visit.patient.address : ''"></p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs" x-text="visitStatusLabel(visit.status)"></span>
                                    <span class="rounded bg-violet-100 px-1.5 py-0.5 text-xs text-violet-800" x-show="visit.role === 'Begleitung'">Begleitung</span>
                                    <span class="rounded bg-sky-100 px-1.5 py-0.5 text-xs text-sky-800" x-show="visit.requiredStaff > 1">2 Personen</span>
                                </div>
                            </div>
                        </button>

                        <div x-show="open === visit.id" x-collapse class="space-y-3 border-t border-slate-100 px-4 pb-4 pt-3">
                            <template x-if="visit.patient && visit.patient.accessNotes">
                                <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><strong>Zugang:</strong> <span x-text="visit.patient.accessNotes"></span></p>
                            </template>
                            <template x-if="visit.patient && visit.patient.careNotes">
                                <p class="rounded-lg bg-sky-50 p-3 text-sm text-sky-900"><strong>Hinweise:</strong> <span x-text="visit.patient.careNotes"></span></p>
                            </template>
                            <div class="flex gap-2">
                                <a class="flex-1 rounded-lg border border-slate-300 py-2 text-center text-sm font-medium" x-show="visit.patient && visit.patient.lat"
                                   :href="navUrl(visit)" target="_blank" rel="noopener">Navigation</a>
                                <a class="flex-1 rounded-lg border border-slate-300 py-2 text-center text-sm font-medium" x-show="visit.patient && visit.patient.phone"
                                   :href="'tel:' + (visit.patient ? visit.patient.phone : '')">Anrufen</a>
                            </div>

                            <template x-if="visit.tasks.length">
                                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                                    <template x-for="task in visit.tasks" :key="task.id">
                                        <li class="p-3">
                                            <div class="flex items-start gap-3">
                                                <div class="min-w-0 flex-1">
                                                    <p class="font-medium"><span class="text-xs text-slate-500" x-text="task.code"></span> <span x-text="task.title"></span></p>
                                                    <p class="text-sm text-slate-700" x-show="task.instruction" x-text="task.instruction"></p>
                                                    <p class="text-xs text-red-700" x-show="task.status === 'NotDone'" x-text="'Nicht erbracht: ' + (task.note || '')"></p>
                                                </div>
                                                <div class="flex shrink-0 gap-1" x-show="visit.status === 'InProgress'">
                                                    <button type="button" class="h-10 w-10 rounded-lg border text-lg"
                                                            :class="task.status === 'Done' ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300'"
                                                            @click="setTask(task, task.status === 'Done' ? 'Open' : 'Done')" :disabled="busy" aria-label="Erledigt">✓</button>
                                                    <button type="button" class="h-10 w-10 rounded-lg border text-lg"
                                                            :class="task.status === 'NotDone' ? 'border-red-600 bg-red-600 text-white' : 'border-slate-300'"
                                                            @click="notDone(task)" :disabled="busy" aria-label="Nicht erbracht">✕</button>
                                                </div>
                                                <span class="shrink-0 text-lg" x-show="visit.status !== 'InProgress' && task.status === 'Done'">✓</span>
                                            </div>
                                        </li>
                                    </template>
                                </ul>
                            </template>

                            <template x-if="visit.status === 'Pending'">
                                <button type="button" class="w-full rounded-lg bg-teal-600 py-3 font-semibold text-white active:bg-teal-700"
                                        @click="post('visit/' + visit.id + '/status', {action: 'start'})" :disabled="busy">Einsatz starten</button>
                            </template>
                            <template x-if="visit.status === 'InProgress'">
                                <div class="space-y-2">
                                    <textarea class="w-full rounded-lg border border-slate-300 p-2 text-sm" rows="2" placeholder="Notiz zum Einsatz (optional)"
                                              x-model="notes[visit.id]"></textarea>
                                    <button type="button" class="w-full rounded-lg bg-emerald-600 py-3 font-semibold text-white active:bg-emerald-700"
                                            @click="complete(visit)" :disabled="busy">Einsatz abschließen</button>
                                </div>
                            </template>
                            <template x-if="visit.status === 'Completed'">
                                <p class="text-sm text-emerald-700">Abgeschlossen um <span x-text="formatTime(visit.actualEnd)"></span></p>
                            </template>
                        </div>
                    </article>
                </template>
            </section>
        </template>
    </main>

    <%-- SOS --%>
    <div class="fixed inset-x-0 bottom-0 z-20 mx-auto max-w-lg bg-gradient-to-t from-slate-50 via-slate-50 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-6" x-show="hasOpenTour()">
        <button type="button" class="w-full rounded-xl bg-red-600 py-4 text-lg font-bold tracking-wide text-white shadow-lg active:bg-red-700"
                @click="sos.open = true">SOS – Tour unterbrechen</button>
    </div>

    <div class="fixed inset-0 z-30 flex items-end bg-black/50" x-show="sos.open" x-transition.opacity @keydown.escape.window="sos.open = false">
        <div class="mx-auto w-full max-w-lg space-y-4 rounded-t-2xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]" @click.outside="sos.open = false">
            <h2 class="text-lg font-bold text-red-700">Tour unterbrechen</h2>
            <p class="text-sm text-slate-600">Offene Einsätze gehen sofort an die PDL zur Neuverteilung. Abgeschlossene bleiben bei dir dokumentiert.</p>
            <label class="block text-sm font-medium">Grund
                <select class="mt-1 w-full rounded-lg border border-slate-300 p-2" x-model="sos.preset">
                    <option value="">Bitte wählen …</option>
                    <option>Verletzung / Unfall</option>
                    <option>Plötzliche Erkrankung</option>
                    <option>Fahrzeugpanne</option>
                    <option>Notfall beim Patienten</option>
                    <option value="other">Sonstiges</option>
                </select>
            </label>
            <textarea class="w-full rounded-lg border border-slate-300 p-2 text-sm" rows="2" placeholder="Details"
                      x-model="sos.details" x-show="sos.preset !== ''"></textarea>
            <template x-if="runningVisit()">
                <fieldset class="space-y-2 rounded-lg bg-slate-50 p-3">
                    <legend class="text-sm font-medium">Einsatz bei <span x-text="runningVisit().patient.name"></span> ist:</legend>
                    <label class="flex items-center gap-2"><input type="radio" value="completed" x-model="sos.outcome"> abgeschlossen</label>
                    <label class="flex items-center gap-2"><input type="radio" value="not_completed" x-model="sos.outcome"> nicht abgeschlossen</label>
                </fieldset>
            </template>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" class="rounded-lg border border-slate-300 py-3 font-medium" @click="sos.open = false">Abbrechen</button>
                <button type="button" class="rounded-lg bg-red-600 py-3 font-bold text-white disabled:opacity-50"
                        :disabled="busy || !sosValid()" @click="sendSos()">SOS senden</button>
            </div>
        </div>
    </div>
</div>
</body>
</html>
