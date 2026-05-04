import os

def replace_in_file(filepath, replacements):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    for old, new in replacements:
        content = content.replace(old, new)
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)

replacements_index = [
    (
        '''                 <?php if(!empty($profile['profile_photo'])): ?>\n                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-9 h-9 rounded-full object-cover border-2 border-slate-200 dark:border-white/10 group-hover:rotate-12 transition shadow-sm" decoding="async">\n                <?php else: ?>''',
        '''                 <?php if(!empty($profile['profile_photo'])): ?>\n                    <img src="<?php echo $profile['profile_photo']; ?>" alt="<?php echo htmlspecialchars($siteTitle); ?>" class="w-9 h-9 rounded-full object-cover border-2 border-slate-200 dark:border-white/10 group-hover:rotate-12 transition shadow-sm" loading="lazy" decoding="async">\n                <?php else: ?>'''
    ),
    (
        '''            <a href="#home" class="flex items-center gap-2 group">\n                <?php if(!empty($profile['profile_photo'])): ?>\n                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 dark:border-white/10">\n                <?php else: ?>''',
        '''            <a href="#home" class="flex items-center gap-2 group">\n                <?php if(!empty($profile['profile_photo'])): ?>\n                    <img src="<?php echo $profile['profile_photo']; ?>" alt="<?php echo htmlspecialchars($siteTitle); ?>" class="w-8 h-8 rounded-full object-cover border border-slate-200 dark:border-white/10" loading="lazy" decoding="async">\n                <?php else: ?>'''
    ),
    (
        '''                <div class="flex items-center gap-2 mb-2 pb-2 border-b border-slate-100/80 dark:border-slate-700">\n                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-5 h-5 rounded-full object-cover">\n                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"><?php echo t('hero.typing', ['name' => $firstName], $lang); ?></span>''',
        '''                <div class="flex items-center gap-2 mb-2 pb-2 border-b border-slate-100/80 dark:border-slate-700">\n                    <img src="<?php echo $profile['profile_photo']; ?>" alt="<?php echo htmlspecialchars($siteTitle); ?>" class="w-5 h-5 rounded-full object-cover" loading="lazy" decoding="async">\n                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"><?php echo t('hero.typing', ['name' => $firstName], $lang); ?></span>'''
    ),
    (
        '''                <div class="project-card group relative bg-lightcard dark:bg-darkcard rounded-3xl overflow-hidden border border-black/5 dark:border-white/5 shadow-md hover:shadow-xl hover:shadow-primary/10 transition duration-500 flex flex-col h-full" data-category="<?php echo htmlspecialchars($projectCategory); ?>" data-aos="fade-up">\n                    <div class="h-56 overflow-hidden relative flex-shrink-0">\n                        <img src="<?php echo $p['image_url']; ?>" class="w-full h-full object-cover transition duration-700 group-hover:scale-110" loading="lazy" decoding="async">\n                        <div class="absolute top-4 right-4"><span class="text-[10px] font-bold bg-white/90 dark:bg-black/70 text-slate-900 dark:text-white px-3 py-1 rounded-full backdrop-blur-md border border-white/10 shadow-sm"><?php echo htmlspecialchars($projectCategory); ?></span></div>''',
        '''                <div class="project-card group relative bg-lightcard dark:bg-darkcard rounded-3xl overflow-hidden border border-black/5 dark:border-white/5 shadow-md hover:shadow-xl hover:shadow-primary/10 transition duration-500 flex flex-col h-full" data-category="<?php echo htmlspecialchars($projectCategory); ?>" data-aos="fade-up">\n                    <div class="h-56 overflow-hidden relative flex-shrink-0">\n                        <img src="<?php echo $p['image_url']; ?>" alt="<?php echo htmlspecialchars(localizedField($p, 'title', $lang)); ?>" class="w-full h-full object-cover transition duration-700 group-hover:scale-110" loading="lazy" decoding="async">\n                        <div class="absolute top-4 right-4"><span class="text-[10px] font-bold bg-white/90 dark:bg-black/70 text-slate-900 dark:text-white px-3 py-1 rounded-full backdrop-blur-md border border-white/10 shadow-sm"><?php echo htmlspecialchars($projectCategory); ?></span></div>'''
    ),
    (
        '''                            <?php if($s['icon_url']): ?>\n                                <?php if(strpos($s['icon_url'], 'fa')===0): ?>\n                                    <i class="<?php echo $s['icon_url']; ?> text-3xl text-slate-400 group-hover:text-primary transition"></i>\n                                <?php else: ?>\n                                    <img src="<?php echo $s['icon_url']; ?>" class="w-8 h-8 object-contain opacity-80 group-hover:opacity-100 transition" loading="lazy" decoding="async">\n                                <?php endif; ?>\n                            <?php else: ?>''',
        '''                            <?php if($s['icon_url']): ?>\n                                <?php if(strpos($s['icon_url'], 'fa')===0): ?>\n                                    <i class="<?php echo $s['icon_url']; ?> text-3xl text-slate-400 group-hover:text-primary transition"></i>\n                                <?php else: ?>\n                                    <img src="<?php echo $s['icon_url']; ?>" alt="<?php echo htmlspecialchars(localizedField($s, 'skill_name', $lang)); ?>" class="w-8 h-8 object-contain opacity-80 group-hover:opacity-100 transition" loading="lazy" decoding="async">\n                                <?php endif; ?>\n                            <?php else: ?>'''
    ),
    (
        '''                    <a href="<?php echo getBlogLink($b, $lang); ?>" class="group bg-white dark:bg-darkcard rounded-2xl overflow-hidden shadow-sm hover:shadow-xl hover:-translate-y-1 transition duration-300 border border-black/5 dark:border-white/5 block">\n                        <div class="h-48 overflow-hidden">\n                            <img src="<?php echo $b['image_url']; ?>" class="w-full h-full object-cover transition duration-500 group-hover:scale-110" loading="lazy" decoding="async">\n                        </div>\n                        <div class="p-6">''',
        '''                    <a href="<?php echo getBlogLink($b, $lang); ?>" class="group bg-white dark:bg-darkcard rounded-2xl overflow-hidden shadow-sm hover:shadow-xl hover:-translate-y-1 transition duration-300 border border-black/5 dark:border-white/5 block">\n                        <div class="h-48 overflow-hidden">\n                            <img src="<?php echo $b['image_url']; ?>" alt="<?php echo htmlspecialchars(localizedField($b, 'title', $lang)); ?>" class="w-full h-full object-cover transition duration-500 group-hover:scale-110" loading="lazy" decoding="async">\n                        </div>\n                        <div class="p-6">'''
    )
]

replacements_article = [
    (
        '''    <!-- Open Graph -->\n    <meta property="og:title" content="<?php echo htmlspecialchars($articleTitle); ?>">\n    <meta property="og:description" content="<?php echo htmlspecialchars($articleMeta); ?>">\n    <meta property="og:image" content="<?php echo $baseUrl . $art['image_url']; ?>">\n    <meta property="og:type" content="article">''',
        '''    <!-- Open Graph -->\n    <meta property="og:url" content="<?php echo $currentUrl; ?>">\n    <meta property="og:title" content="<?php echo htmlspecialchars($articleTitle); ?>">\n    <meta property="og:description" content="<?php echo htmlspecialchars($articleMeta); ?>">\n    <meta property="og:image" content="<?php echo $baseUrl . $art['image_url']; ?>">\n    <meta property="og:type" content="article">'''
    ),
    (
        '''            <div class="flex flex-col lg:flex-row items-center gap-6 border-b border-slate-200 dark:border-white/10 pb-8">\n                <div class="flex items-center gap-4">\n                    <img src="<?php echo $profile['profile_photo']; ?>" class="w-12 h-12 rounded-full border-2 border-white dark:border-white/10 shadow-md object-cover">\n                    <div>''',
        '''            <div class="flex flex-col lg:flex-row items-center gap-6 border-b border-slate-200 dark:border-white/10 pb-8">\n                <div class="flex items-center gap-4">\n                    <img src="<?php echo $profile['profile_photo']; ?>" alt="<?php echo htmlspecialchars($profile['name']); ?>" class="w-12 h-12 rounded-full border-2 border-white dark:border-white/10 shadow-md object-cover" loading="lazy" decoding="async">\n                    <div>'''
    ),
    (
        '''            <article class="lg:col-span-8">\n                <?php if($art['image_url']): ?>\n                    <div class="rounded-3xl overflow-hidden mb-10 border border-slate-200 dark:border-white/10 shadow-2xl relative group">\n                        <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent opacity-0 group-hover:opacity-100 transition duration-500"></div>\n                        <img src="<?php echo $art['image_url']; ?>" class="w-full h-auto object-cover transform group-hover:scale-105 transition duration-700 ease-out">\n                    </div>\n                <?php endif; ?>''',
        '''            <article class="lg:col-span-8">\n                <?php if($art['image_url']): ?>\n                    <div class="rounded-3xl overflow-hidden mb-10 border border-slate-200 dark:border-white/10 shadow-2xl relative group">\n                        <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent opacity-0 group-hover:opacity-100 transition duration-500"></div>\n                        <img src="<?php echo $art['image_url']; ?>" alt="<?php echo htmlspecialchars($articleTitle); ?>" class="w-full h-auto object-cover transform group-hover:scale-105 transition duration-700 ease-out" fetchpriority="high" loading="eager" decoding="sync">\n                    </div>\n                <?php endif; ?>'''
    ),
    (
        '''                <!-- Widget: Author -->\n                <div class="bg-white dark:bg-darkcard p-6 rounded-3xl border border-slate-200 dark:border-white/5 shadow-lg sticky top-24">\n                    <div class="flex items-center gap-4 mb-4">\n                        <img src="<?php echo $profile['profile_photo']; ?>" class="w-16 h-16 rounded-full border-4 border-slate-50 dark:border-white/5 object-cover">\n                        <div>''',
        '''                <!-- Widget: Author -->\n                <div class="bg-white dark:bg-darkcard p-6 rounded-3xl border border-slate-200 dark:border-white/5 shadow-lg sticky top-24">\n                    <div class="flex items-center gap-4 mb-4">\n                        <img src="<?php echo $profile['profile_photo']; ?>" alt="<?php echo htmlspecialchars($profile['name']); ?>" class="w-16 h-16 rounded-full border-4 border-slate-50 dark:border-white/5 object-cover" loading="lazy" decoding="async">\n                        <div>'''
    ),
    (
        '''                        <?php while($latest = mysqli_fetch_assoc($latestQ)): ?>\n                        <a href="<?php echo getBlogLink($latest['slug'], $latest['id'] ?? 0, $lang); ?>" class="flex gap-4 group">\n                            <?php if(!empty($latest['image_url'])): ?>\n                            <div class="w-20 h-20 flex-shrink-0 rounded-xl overflow-hidden">\n                                <img src="<?php echo $latest['image_url']; ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">\n                            </div>\n                            <?php endif; ?>''',
        '''                        <?php while($latest = mysqli_fetch_assoc($latestQ)): ?>\n                        <a href="<?php echo getBlogLink($latest['slug'], $latest['id'] ?? 0, $lang); ?>" class="flex gap-4 group">\n                            <?php if(!empty($latest['image_url'])): ?>\n                            <div class="w-20 h-20 flex-shrink-0 rounded-xl overflow-hidden">\n                                <img src="<?php echo $latest['image_url']; ?>" alt="<?php echo htmlspecialchars(localizedField($latest, 'title', $lang)); ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500" loading="lazy" decoding="async">\n                            </div>\n                            <?php endif; ?>'''
    )
]

replacements_project = [
    (
        '''    <!-- Open Graph -->\n    <meta property="og:title" content="<?php echo htmlspecialchars($projectTitle); ?> | <?php echo t('project.detail', [], $lang); ?>">\n    <meta property="og:description" content="<?php echo htmlspecialchars($projectMeta); ?>">\n    <meta property="og:image" content="<?php echo $baseUrl . $project['image_url']; ?>">\n    <meta property="og:type" content="article">''',
        '''    <!-- Open Graph -->\n    <meta property="og:url" content="<?php echo $currentUrl; ?>">\n    <meta property="og:title" content="<?php echo htmlspecialchars($projectTitle); ?> | <?php echo t('project.detail', [], $lang); ?>">\n    <meta property="og:description" content="<?php echo htmlspecialchars($projectMeta); ?>">\n    <meta property="og:image" content="<?php echo $baseUrl . $project['image_url']; ?>">\n    <meta property="og:type" content="article">'''
    ),
    (
        '''            <div class="lg:col-span-2">\n                <div class="rounded-3xl overflow-hidden border border-slate-200 dark:border-white/10 shadow-2xl mb-12 bg-slate-100 dark:bg-black">\n                    <img src="<?php echo $project['image_url']; ?>" class="w-full h-auto object-cover">\n                </div>''',
        '''            <div class="lg:col-span-2">\n                <div class="rounded-3xl overflow-hidden border border-slate-200 dark:border-white/10 shadow-2xl mb-12 bg-slate-100 dark:bg-black">\n                    <img src="<?php echo $project['image_url']; ?>" alt="<?php echo htmlspecialchars($projectTitle); ?>" class="w-full h-auto object-cover" fetchpriority="high" loading="eager" decoding="sync">\n                </div>'''
    ),
    (
        '''                    <div class="grid grid-cols-2 gap-3">\n                        <?php foreach($gallery as $img): ?>\n                        <div class="aspect-square rounded-2xl overflow-hidden border border-slate-200 dark:border-white/10 cursor-pointer group relative" onclick="window.open('<?php echo $img['image_url']; ?>','_blank')">\n                            <img src="<?php echo $img['image_url']; ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">\n                        </div>\n                        <?php endforeach; ?>\n                    </div>''',
        '''                    <div class="grid grid-cols-2 gap-3">\n                        <?php foreach($gallery as $img): ?>\n                        <div class="aspect-square rounded-2xl overflow-hidden border border-slate-200 dark:border-white/10 cursor-pointer group relative" onclick="window.open('<?php echo $img['image_url']; ?>','_blank')">\n                            <img src="<?php echo $img['image_url']; ?>" alt="<?php echo htmlspecialchars($projectTitle . ' - Gallery Image'); ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500" loading="lazy" decoding="async">\n                        </div>\n                        <?php endforeach; ?>\n                    </div>'''
    )
]

base_dir = r'c:\\Users\\user\\OneDrive\\Documents\\porto radhit\\radhityasofwan.my.id\\'

replace_in_file(base_dir + 'index.php', replacements_index)
replace_in_file(base_dir + 'article.php', replacements_article)
replace_in_file(base_dir + 'project-details.php', replacements_project)

print('Replacements applied.')
