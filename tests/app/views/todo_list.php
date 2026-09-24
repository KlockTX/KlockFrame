<?php
/**
 * 待办列表片段（整页与片段请求共用）
 *
 * @var array $items
 */
echo kf_ul(
    ...array_map(static function (array $it): KF_Element {
        return kf_li(
            kf_span($it['text']),
            kf_button('删除')
                ->delete('/todo/' . $it['id'])
                ->hx_target('#todo-list')
                ->swap('outerHTML')
                ->confirm('确定删除？')
        )->id('todo-' . $it['id'])->class($it['done'] ? 'done' : 'open');
    }, $items ?? [])
)->id('todo-list')->class('todos');
