<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

// No props needed - registration is disabled

const features = [
    {
        icon: '✈️',
        title: 'Best Prices',
        description: 'Competitive rates on flights, hotels, and packages worldwide.',
    },
    {
        icon: '🏆',
        title: 'Trusted Partners',
        description: 'Work with top airlines and hotel chains globally.',
    },
    {
        icon: '🌍',
        title: 'Global Coverage',
        description: 'Access to 1000+ destinations across all continents.',
    },
    {
        icon: '💬',
        title: '24/7 Support',
        description: 'Round-the-clock customer service for your peace of mind.',
    },
];

const destinations = [
    {
        name: 'Paris, France',
        image: 'https://images.unsplash.com/photo-1502602898657-3e91760cbb34?w=800&auto=format&fit=crop',
        price: 'From $899',
    },
    {
        name: 'Tokyo, Japan',
        image: 'https://images.unsplash.com/photo-1540959733332-eab4deabeeaf?w=800&auto=format&fit=crop',
        price: 'From $1,299',
    },
    {
        name: 'Bali, Indonesia',
        image: 'https://images.unsplash.com/photo-1537996194471-e657df975ab4?w=800&auto=format&fit=crop',
        price: 'From $699',
    },
    {
        name: 'Dubai, UAE',
        image: 'https://images.unsplash.com/photo-1512453979798-5ea266f8880c?w=800&auto=format&fit=crop',
        price: 'From $799',
    },
    {
        name: 'New York, USA',
        image: 'https://images.unsplash.com/photo-1496442226666-8d4d0e62e6e9?w=800&auto=format&fit=crop',
        price: 'From $599',
    },
    {
        name: 'Santorini, Greece',
        image: 'https://images.unsplash.com/photo-1613395877344-13d4a8e0d49e?w=800&auto=format&fit=crop',
        price: 'From $999',
    },
];

const stats = [
    { number: '15+', label: 'Years Experience' },
    { number: '50K+', label: 'Happy Travelers' },
    { number: '1000+', label: 'Destinations' },
    { number: '98%', label: 'Satisfaction Rate' },
];
</script>

<template>
    <Head title="AIRPROOF LTD - Your Journey, Our Passion">
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous" />
        <link
            href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600&display=swap"
            rel="stylesheet"
        />
    </Head>

    <!-- Main Container -->
    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-cyan-50 dark:from-gray-900 dark:via-gray-800 dark:to-cyan-950">
        <!-- Navigation Header -->
        <header class="fixed top-0 left-0 right-0 z-50 bg-white/80 backdrop-blur-md shadow-sm dark:bg-gray-900/80">
            <nav class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex h-20 items-center justify-between">
                    <!-- Logo -->
                    <div class="flex items-center space-x-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-cyan-500 to-blue-600 text-2xl font-bold text-white shadow-lg">
                            A
                        </div>
                        <div>
                            <h1 class="text-xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-2xl" style="font-family: 'Poppins', sans-serif">
                                AIRPROOF LTD
                            </h1>
                            <p class="text-xs text-cyan-600 dark:text-cyan-400">Your Journey, Our Passion</p>
                        </div>
                    </div>

                    <!-- Navigation Links -->
                    <div class="flex items-center gap-4">
                        <Link
                            v-if="$page.props.auth.user"
                            href="/dashboard"
                            class="hidden rounded-lg bg-gradient-to-r from-cyan-500 to-blue-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg transition-all duration-300 hover:shadow-xl hover:scale-105 sm:inline-block"
                        >
                            Dashboard
                        </Link>
                        <template v-else>
                            <Link
                                href="/login"
                                class="rounded-lg bg-gradient-to-r from-cyan-500 to-blue-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg transition-all duration-300 hover:shadow-xl hover:scale-105"
                            >
                                Log in
                            </Link>
                        </template>
                    </div>
                </div>
            </nav>
        </header>

        <!-- Hero Section -->
        <section class="relative overflow-hidden pt-32 pb-20 sm:pt-40 sm:pb-32">
            <!-- Background Pattern -->
            <div class="absolute inset-0 -z-10 opacity-20">
                <div class="absolute -left-4 top-0 h-96 w-96 rounded-full bg-cyan-400 blur-3xl"></div>
                <div class="absolute right-0 top-40 h-96 w-96 rounded-full bg-blue-400 blur-3xl"></div>
            </div>

            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-4xl text-center">
                    <!-- Main Heading -->
                    <h2 class="mb-6 text-5xl font-extrabold leading-tight text-gray-900 dark:text-white sm:text-6xl lg:text-7xl" style="font-family: 'Poppins', sans-serif">
                        Explore the World with
                        <span class="bg-gradient-to-r from-cyan-500 via-blue-600 to-purple-600 bg-clip-text text-transparent">
                            Confidence
                        </span>
                    </h2>

                    <p class="mx-auto mb-10 max-w-2xl text-lg text-gray-600 dark:text-gray-300 sm:text-xl" style="font-family: 'Inter', sans-serif">
                        Discover extraordinary destinations with AIRPROOF LTD. We turn your travel dreams into unforgettable memories with personalized service and unbeatable deals.
                    </p>

                    <!-- CTA Buttons -->
                    <div class="flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <Link
                            href="/login"
                            class="group relative inline-flex items-center gap-2 overflow-hidden rounded-full bg-gradient-to-r from-cyan-500 via-blue-600 to-purple-600 px-8 py-4 text-lg font-bold text-white shadow-2xl transition-all duration-300 hover:shadow-cyan-500/50 hover:scale-105"
                        >
                            <span>Access Dashboard</span>
                            <svg class="h-5 w-5 transition-transform duration-300 group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </Link>
                        <button class="flex items-center gap-2 rounded-full border-2 border-gray-300 bg-white/50 px-8 py-4 text-lg font-semibold text-gray-700 backdrop-blur-sm transition-all duration-300 hover:border-cyan-500 hover:bg-white dark:border-gray-600 dark:bg-gray-800/50 dark:text-gray-200 dark:hover:border-cyan-400">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                            </svg>
                            Contact Us
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section class="py-20 bg-white dark:bg-gray-900">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="mb-16 text-center">
                    <h3 class="mb-4 text-4xl font-bold text-gray-900 dark:text-white sm:text-5xl" style="font-family: 'Poppins', sans-serif">
                        Why Choose AIRPROOF LTD?
                    </h3>
                    <p class="mx-auto max-w-2xl text-lg text-gray-600 dark:text-gray-400">
                        We provide exceptional travel services backed by years of expertise and dedication.
                    </p>
                </div>

                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(feature, index) in features"
                        :key="index"
                        class="group relative overflow-hidden rounded-2xl bg-gradient-to-br from-white to-gray-50 p-8 shadow-lg transition-all duration-500 hover:-translate-y-2 hover:shadow-2xl dark:from-gray-800 dark:to-gray-850"
                    >
                        <div class="absolute -right-8 -top-8 h-32 w-32 rounded-full bg-gradient-to-br from-cyan-500/10 to-blue-600/10 transition-transform duration-500 group-hover:scale-150"></div>
                        <div class="relative">
                            <div class="mb-4 text-5xl">{{ feature.icon }}</div>
                            <h4 class="mb-3 text-xl font-bold text-gray-900 dark:text-white">{{ feature.title }}</h4>
                            <p class="text-gray-600 dark:text-gray-400">{{ feature.description }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Popular Destinations -->
        <section class="py-20 bg-gradient-to-br from-slate-50 to-cyan-50 dark:from-gray-850 dark:to-cyan-950">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="mb-16 text-center">
                    <h3 class="mb-4 text-4xl font-bold text-gray-900 dark:text-white sm:text-5xl" style="font-family: 'Poppins', sans-serif">
                        Popular Destinations
                    </h3>
                    <p class="mx-auto max-w-2xl text-lg text-gray-600 dark:text-gray-400">
                        From bustling cities to serene beaches, explore our handpicked destinations.
                    </p>
                </div>

                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="(destination, index) in destinations"
                        :key="index"
                        class="group relative overflow-hidden rounded-2xl shadow-xl transition-all duration-500 hover:shadow-2xl hover:scale-105"
                    >
                        <div class="aspect-[4/3] w-full overflow-hidden">
                            <img
                                :src="destination.image"
                                :alt="destination.name"
                                class="h-full w-full object-cover transition-transform duration-700 group-hover:scale-110"
                            />
                        </div>
                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent"></div>
                        <div class="absolute bottom-0 left-0 right-0 p-6 text-white">
                            <h4 class="mb-2 text-2xl font-bold">{{ destination.name }}</h4>
                            <p class="text-lg font-semibold text-cyan-300">{{ destination.price }}</p>
                        </div>
                        <div class="absolute top-4 right-4 rounded-full bg-white/20 p-3 backdrop-blur-md transition-all duration-300 group-hover:bg-cyan-500">
                            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stats Section -->
        <section class="py-20 bg-gradient-to-r from-cyan-600 via-blue-600 to-purple-700">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(stat, index) in stats"
                        :key="index"
                        class="text-center"
                    >
                        <div class="mb-2 text-5xl font-extrabold text-white sm:text-6xl" style="font-family: 'Poppins', sans-serif">
                            {{ stat.number }}
                        </div>
                        <div class="text-lg font-medium text-cyan-100">{{ stat.label }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-20 bg-white dark:bg-gray-900">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-cyan-500 via-blue-600 to-purple-600 px-8 py-16 shadow-2xl sm:px-16">
                    <div class="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-white/10"></div>
                    <div class="absolute -bottom-16 -left-16 h-64 w-64 rounded-full bg-white/10"></div>
                    
                    <div class="relative mx-auto max-w-3xl text-center">
                        <h3 class="mb-6 text-4xl font-bold text-white sm:text-5xl" style="font-family: 'Poppins', sans-serif">
                            Ready to Start Your Adventure?
                        </h3>
                        <p class="mb-8 text-lg text-cyan-50">
                            Join thousands of satisfied travelers. Book your dream vacation today and create memories that last a lifetime.
                        </p>
                        <div class="flex flex-col items-center justify-center gap-4 sm:flex-row">
                            <Link
                                href="/login"
                                class="inline-flex items-center gap-2 rounded-full bg-white px-8 py-4 text-lg font-bold text-cyan-600 shadow-xl transition-all duration-300 hover:shadow-2xl hover:scale-105"
                            >
                                <span>Login Now</span>
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </Link>
                            <button class="inline-flex items-center gap-2 rounded-full border-2 border-white bg-transparent px-8 py-4 text-lg font-semibold text-white transition-all duration-300 hover:bg-white hover:text-cyan-600">
                                Contact Us
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-gray-900 py-12 text-gray-400">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <!-- Company Info -->
                    <div>
                        <div class="mb-4 flex items-center space-x-2">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-cyan-500 to-blue-600 text-lg font-bold text-white">
                                A
                            </div>
                            <span class="text-lg font-bold text-white">AIRPROOF LTD</span>
                        </div>
                        <p class="text-sm">
                            Your trusted travel partner for unforgettable journeys around the world.
                        </p>
                    </div>

                    <!-- Quick Links -->
                    <div>
                        <h5 class="mb-4 font-semibold text-white">Quick Links</h5>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="hover:text-cyan-400 transition-colors">About Us</a></li>
                            <li><a href="#" class="hover:text-cyan-400 transition-colors">Destinations</a></li>
                            <li><a href="#" class="hover:text-cyan-400 transition-colors">Services</a></li>
                            <li><a href="#" class="hover:text-cyan-400 transition-colors">Contact</a></li>
                        </ul>
                    </div>

                    <!-- Services -->
                    <div>
                        <h5 class="mb-4 font-semibold text-white">Services</h5>
                        <ul class="space-y-2 text-sm">
                            <li><a href="#" class="hover:text-cyan-400 transition-colors">Flight Booking</a></li>
                            <li><a href="#" class="hover:text-cyan-400 transition-colors">Hotel Reservations</a></li>
                            <li><a href="#" class="hover:text-cyan-400 transition-colors">Tour Packages</a></li>
                            <li><a href="#" class="hover:text-cyan-400 transition-colors">Travel Insurance</a></li>
                        </ul>
                    </div>

                    <!-- Contact -->
                    <div>
                        <h5 class="mb-4 font-semibold text-white">Contact Us</h5>
                        <ul class="space-y-2 text-sm">
                            <li>📧 info@airproof.com</li>
                            <li>📞 +1 (555) 123-4567</li>
                            <li>📍 123 Travel Street, NY 10001</li>
                        </ul>
                        <!-- Social Links -->
                        <div class="mt-4 flex gap-3">
                            <a href="#" class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-800 hover:bg-cyan-500 transition-colors">
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </a>
                            <a href="#" class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-800 hover:bg-cyan-500 transition-colors">
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg>
                            </a>
                            <a href="#" class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-800 hover:bg-cyan-500 transition-colors">
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06l.045.03zm0 3.678c-3.405 0-6.162 2.76-6.162 6.162 0 3.405 2.76 6.162 6.162 6.162 3.405 0 6.162-2.76 6.162-6.162 0-3.405-2.76-6.162-6.162-6.162zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm7.846-10.405c0 .795-.646 1.44-1.44 1.44-.795 0-1.44-.646-1.44-1.44 0-.794.646-1.439 1.44-1.439.793-.001 1.44.645 1.44 1.439z"/></svg>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="mt-12 border-t border-gray-800 pt-8 text-center text-sm">
                    <p>&copy; 2025 AIRPROOF LTD. All rights reserved. | Designed with ❤️ for travelers worldwide.</p>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* Custom scrollbar for modern look */
::-webkit-scrollbar {
    width: 10px;
}

::-webkit-scrollbar-track {
    background: #f1f1f1;
}

::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #06b6d4, #2563eb);
    border-radius: 5px;
}

::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #0891b2, #1d4ed8);
}

/* Smooth scroll behavior */
html {
    scroll-behavior: smooth;
}
</style>
