<x-layouts.app title="Connexion" :nav="false">
    <div class="mx-auto mt-16 max-w-sm">
        <h1 class="text-2xl font-semibold tracking-tight">SlowMail</h1>
        <p class="mt-1 text-sm text-stone-500">Entrez le mot de passe pour accéder à l'interface.</p>

        @unless ($configured)
            <div class="mt-6 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
                <code class="font-mono">SLOWMAIL_PASSWORD</code> n'est pas défini dans la configuration (<code class="font-mono">docker-compose.yml</code> ou <code class="font-mono">.env</code>) :
                personne ne peut se connecter tant qu'il est vide.
            </div>
        @endunless

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="password" class="block text-sm font-medium">Mot de passe</label>
                <input id="password" name="password" type="password" required autofocus autocomplete="current-password"
                    class="mt-1 block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                @error('password')
                    <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit"
                class="w-full rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-700">
                Entrer
            </button>
        </form>
    </div>
</x-layouts.app>
