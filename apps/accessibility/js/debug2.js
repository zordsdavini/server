
alert('dark' + window.matchMedia('(prefers-color-scheme: dark)').matches);
alert('more' + window.matchMedia('(prefers-contrast: more)').matches);
alert('darkmore' + window.matchMedia('(prefers-color-scheme: dark) and (prefers-contrast: more)').matches);
alert('dark-more' + window.matchMedia('(prefers-color-scheme: dark) and not(prefers-contrast: more)').matches);
alert('-darkmore' + window.matchMedia('not(prefers-color-scheme: dark) and (prefers-contrast: more)').matches);
